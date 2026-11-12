import { createHash } from 'node:crypto';
import {
    existsSync,
    lstatSync,
    mkdirSync,
    readFileSync,
    readlinkSync,
    readdirSync,
    unlinkSync,
    writeFileSync,
} from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';

const defaultSource = fileURLToPath(new URL('./', import.meta.url));
const hash = (content) => createHash('sha256').update(content).digest('hex');
const json = (value) => JSON.stringify(value, null, 4) + '\n';
const isVersion = (value) =>
    typeof value === 'string' && /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.test(value);
const skillName = /^atb-[a-z0-9]+(?:-[a-z0-9]+)*$/;

function regularPath(root, relative) {
    const segments = relative.split('/');
    if (
        path.isAbsolute(relative) ||
        segments.some((segment) => !segment || segment === '..' || segment === '.') ||
        relative.includes('\\')
    ) {
        throw new Error(`Unsafe standards path: ${relative}`);
    }
    let current = root;
    for (const segment of segments) {
        current = path.join(current, segment);
        if (lstatSync(current, { throwIfNoEntry: false })?.isSymbolicLink()) {
            const skill = path
                .relative(root, current)
                .split(path.sep)
                .join('/')
                .match(/^\.agents\/skills\/(atb-[a-z0-9-]+)$/);
            const destination = path.resolve(path.dirname(current), readlinkSync(current));
            if (skill && destination === path.resolve(root, '.ai/skills', skill[1])) continue;
            throw new Error(`Standards paths must not use symlinks: ${current}`);
        }
    }
    return current;
}

function readBundle(source, { unsealed = false } = {}) {
    const manifest = JSON.parse(readFileSync(path.join(source, 'manifest.json')));
    if (
        manifest.schemaVersion !== 1 ||
        !isVersion(manifest.version) ||
        !Array.isArray(manifest.skills) ||
        manifest.skills.length === 0 ||
        new Set(manifest.skills).size !== manifest.skills.length ||
        manifest.skills.some((name) => !skillName.test(name) || name.length > 64)
    ) {
        throw new Error('Invalid standards manifest.');
    }
    const files = {};
    function visit(relative) {
        const filename = regularPath(source, relative);
        const stat = lstatSync(filename);
        if (stat.isDirectory()) {
            for (const name of readdirSync(filename).sort()) {
                visit(`${relative}/${name}`);
            }
        } else if (stat.isFile()) {
            files[relative] = readFileSync(filename);
        } else {
            throw new Error(`Unsupported standards resource: ${relative}`);
        }
    }
    for (const name of [...manifest.skills].sort((left, right) =>
        left.localeCompare(right, 'en'),
    )) {
        visit(`skills/${name}`);
        const entry = files[`skills/${name}/SKILL.md`]?.toString();
        const metadata = entry?.match(/^---\n([\s\S]*?)\n---\n/);
        if (
            !metadata ||
            !metadata[1].split('\n').includes(`name: ${name}`) ||
            !/^description: \S.+$/m.test(metadata[1])
        ) {
            throw new Error(`Missing or invalid skill metadata: ${name}`);
        }
    }
    visit('sync.mjs');
    const digests = Object.fromEntries(
        Object.entries(files).map(([name, content]) => [name, hash(content)]),
    );
    const sha256 = hash(json(digests));
    if (!unsealed && sha256 !== manifest.sha256) {
        throw new Error('Source changed. Release a new standards version before synchronizing.');
    }
    return { manifest, files, digests, sha256 };
}

export function releaseStandards(source, version) {
    const bundle = readBundle(source, { unsealed: true });
    if (!isVersion(version)) throw new Error('Use a numeric major.minor.patch version.');
    if (version === bundle.manifest.version && bundle.sha256 === bundle.manifest.sha256) {
        return;
    }
    const next = version.split('.').map(Number);
    const previous = bundle.manifest.version.split('.').map(Number);
    const difference = next.findIndex((number, index) => number !== previous[index]);
    if (difference === -1 || next[difference] < previous[difference]) {
        throw new Error('Changed standards require a higher version.');
    }
    writeFileSync(
        path.join(source, 'manifest.json'),
        json({ ...bundle.manifest, version, sha256: bundle.sha256 }),
    );
}

export function syncStandards({ source = defaultSource, target, write = false, version }) {
    source = path.resolve(source);
    target = path.resolve(target);
    const bundle = readBundle(source);
    if ((write || version !== undefined) && version !== bundle.manifest.version) {
        throw new Error(`Pin the source version with --version ${bundle.manifest.version}.`);
    }
    const desired = {};
    for (const [relative, content] of Object.entries(bundle.files)) {
        desired[`.ai/${relative}`] = content;
        if (relative.startsWith('skills/')) desired[`.agents/${relative}`] = content;
    }
    desired['.ai/manifest.json'] = readFileSync(path.join(source, 'manifest.json'));
    const lockPath = regularPath(target, '.ai/standards.lock.json');
    const previous = existsSync(lockPath) ? JSON.parse(readFileSync(lockPath)) : { files: {} };
    if (!previous.files || typeof previous.files !== 'object' || Array.isArray(previous.files)) {
        throw new Error('Invalid standards lock file.');
    }
    const paths = [...new Set([...Object.keys(desired), ...Object.keys(previous.files)])].sort();
    const changes = [];
    const conflicts = [];
    for (const relative of paths) {
        if (
            !/^\.(ai|agents)\/skills\/atb-[a-z0-9-]+\/.+/.test(relative) &&
            !['.ai/manifest.json', '.ai/sync.mjs'].includes(relative)
        ) {
            throw new Error(`Unmanaged path in standards lock: ${relative}`);
        }
        const filename = regularPath(target, relative);
        const current = existsSync(filename) ? hash(readFileSync(filename)) : null;
        const wanted = desired[relative] ? hash(desired[relative]) : null;
        if (current === wanted) continue;
        changes.push(relative);
        if (current !== null && current !== previous.files[relative]) {
            conflicts.push(relative);
        }
    }
    const lock = {
        schemaVersion: 1,
        version: bundle.manifest.version,
        sha256: bundle.sha256,
        files: Object.fromEntries(
            Object.entries(desired).map(([relative, content]) => [relative, hash(content)]),
        ),
    };
    if (!existsSync(lockPath) || readFileSync(lockPath, 'utf8') !== json(lock)) {
        changes.push('.ai/standards.lock.json');
    }
    if (write) {
        if (conflicts.length) {
            throw new Error(`Preserve local edits before synchronizing: ${conflicts.join(', ')}`);
        }
        for (const relative of changes.filter((name) => name !== '.ai/standards.lock.json')) {
            const filename = regularPath(target, relative);
            if (desired[relative]) {
                mkdirSync(path.dirname(filename), { recursive: true });
                writeFileSync(filename, desired[relative]);
            } else if (existsSync(filename)) {
                unlinkSync(filename);
            }
        }
        mkdirSync(path.dirname(lockPath), { recursive: true });
        if (changes.includes('.ai/standards.lock.json')) writeFileSync(lockPath, json(lock));
    }
    return { version: bundle.manifest.version, changes };
}

export function runCLI() {
    try {
        const { values } = parseArgs({
            options: {
                source: { type: 'string', default: defaultSource },
                target: { type: 'string', default: process.cwd() },
                version: { type: 'string' },
                release: { type: 'string' },
                write: { type: 'boolean', default: false },
                check: { type: 'boolean', default: false },
            },
        });
        if ((values.write && values.check) || (values.release && (values.write || values.check))) {
            throw new Error('Choose one operation: --check, --write, or --release <version>.');
        }
        if (values.release) {
            releaseStandards(values.source, values.release);
            console.log(`Released standards ${values.release}.`);
        } else {
            const result = syncStandards(values);
            console.log(
                `Standards ${result.version}: ${result.changes.length} ${values.write ? 'synchronized' : 'pending'} files.`,
            );
            for (const name of result.changes) console.log(name);
            if (!values.write && result.changes.length) process.exitCode = 1;
        }
    } catch (error) {
        console.error(error.message);
        process.exitCode = 1;
    }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) runCLI();
