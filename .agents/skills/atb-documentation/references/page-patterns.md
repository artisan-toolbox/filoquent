# Documentation page patterns

Choose the pattern that matches the reader's task. Link to another page when the reader needs a different mode.

## Tutorial

Help a beginner build a concrete result.
State the starting environment and required setup, then provide ordered steps with visible outcomes.
Give complete example inputs. Verify the path from installation to the first result.
End with the next guide the learner can use.

## How-to guide

Help a reader complete a specific task in an existing project.
State the goal, assumptions, prerequisites, ordered actions, and expected result.
Include relevant variations and failure recovery without turning the guide into an exhaustive reference.

## Reference

Describe facts for lookup in a consistent format that follows the public API or configuration structure.
For each applicable entry, state its name, type, default, accepted values, behavior, errors, and example.
State the supported version and preserve stable anchors.
Link to a task guide for setup and use.

## Explanation

Explain one bounded concept or architectural decision.
Describe context, constraints, behavior, alternatives, trade-offs, and practical limits.
Support claims with implementation or other authoritative evidence.
Link to procedures and reference material for the reader's next action.

## Package overview

Use the same entry sequence across packages:

1. Purpose, stability, and documented version.
2. Requirements and starting environment.
3. Short installation command linked to the complete Installation guide.
4. Required configuration for the example, when applicable.
5. Minimal public API example with defined inputs and an observable result.
6. Links to the next task guides, advanced explanations, reference, and development guidance.

Keep compatibility and stability separate from versions installed in the documentation application.
Order navigation by prerequisites. Preserve the owning registry as the single menu source.
