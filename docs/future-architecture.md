# Future Architecture Considerations

This document captures architectural improvements and design decisions to be considered for future versions of StateFlow.

## Status: Under Discussion
Last Updated: 2025-11-30

---

## 1. Type Safety: Replace Arrays with Typed Structures

### Current State
The codebase currently uses plain PHP arrays with docblock type hints in several places:

**Delta (State Changes)**
```php
// Current usage
public function transition(State $currentState, array $desiredDelta): StateWorker
public function provide(State $currentState, array $desiredDelta): Configuration
```

**Collections in Configuration**
```php
/**
 * @param Gate[] $transitionGates
 * @param Action[] $actions
 */
public function __construct(private array $transitionGates, private array $actions)
```

**Collections in TransitionContext**
```php
/**
 * @var ActionResult[]
 */
private array $actions = [];
```

### Proposed Changes

#### 1.1 Delta as Interface/Value Object

**Option A: Interface**
```php
interface Delta
{
    public function has(string $key): bool;
    public function get(string $key): mixed;
    public function all(): array;
    public function keys(): array;
}

class ArrayDelta implements Delta
{
    public function __construct(private array $changes) {}
    // Implementation...
}
```

**Option B: Readonly Value Object**
```php
readonly class Delta
{
    public function __construct(private array $changes) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->changes);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->changes[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->changes;
    }
}
```

**Recommendation: Start with Option B (Value Object)**
- Simpler, less abstraction overhead
- Can evolve to interface later if needed
- Provides type safety and encapsulation
- Immutable by default (readonly)

**Benefits:**
- ✅ Type safety - can't pass wrong type
- ✅ Encapsulation - controlled access to changes
- ✅ Rich API - helper methods like `has()`, `get()`
- ✅ Documentation - self-documenting code
- ✅ Validation - can validate changes in constructor

**Considerations:**
- ⚠️ Breaking change - all transition() calls need updating
- ⚠️ Serialization - needs to support serialization for paused contexts
- 💭 Should Delta support nested changes? `$delta->get('address.city')`
- 💭 Should Delta support merging? `$delta->merge($otherDelta)`

#### 1.2 Typed Collections with Generics

PHP doesn't have native generics, but we can create type-safe collections:

**Option A: Specific Collection Classes**
```php
class GateCollection implements \IteratorAggregate, \Countable
{
    /** @var Gate[] */
    private array $gates = [];

    public function __construct(Gate ...$gates)
    {
        $this->gates = $gates;
    }

    public function add(Gate $gate): void
    {
        $this->gates[] = $gate;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->gates);
    }

    public function count(): int
    {
        return count($this->gates);
    }

    public function toArray(): array
    {
        return $this->gates;
    }
}

class ActionCollection { /* similar */ }
class ActionResultCollection { /* similar */ }
```

**Option B: Generic Collection (using PHPStan/Psalm annotations)**
```php
/**
 * @template T
 */
class Collection implements \IteratorAggregate, \Countable
{
    /** @var T[] */
    private array $items = [];

    /**
     * @param T ...$items
     */
    public function __construct(...$items)
    {
        $this->items = $items;
    }

    // Methods...
}

// Usage with PHPStan/Psalm:
/** @var Collection<Gate> */
$gates = new Collection(...$gateArray);
```

**Option C: Use External Library**
- `doctrine/collections`
- `illuminate/collections` (Laravel)
- `ramsey/collection`

**Recommendation: Option A (Specific Collection Classes)**
- Native PHP type safety (no static analysis needed)
- Can add domain-specific methods (e.g., `GateCollection::findDeny()`)
- No external dependencies
- Clearer intent in code

**Benefits:**
- ✅ Runtime type safety
- ✅ IDE autocomplete
- ✅ Prevent accidental wrong types
- ✅ Can add collection-specific methods
- ✅ Chainable, fluent APIs

**Considerations:**
- ⚠️ More boilerplate code
- ⚠️ Need separate class for each collection type
- 💭 Should collections be immutable or mutable?
- 💭 What methods do we need? (filter, map, first, last, etc.)

---

## 2. Break Up TransitionContext into Sub-Classes

### Current State
`TransitionContext` will grow to contain:
- Initial state
- Current state (after each action)
- Desired delta
- Gate evaluations
- Action executions
- Action skips
- Lock state
- Status metadata (pause/stop reasons)
- Completion flags (completed, paused, stopped, skipped)

**This violates Single Responsibility Principle.**

### Proposed Structure

#### 2.1 Decomposition Strategy

```php
// Main context - orchestrates sub-components
class TransitionContext
{
    public function __construct(
        private State $initialState,
        private Delta $delta,
        private ExecutionHistory $history,
        private ExecutionStatus $status,
        private ?LockState $lockState = null,
    ) {}

    // Delegates to sub-components
    public function getCurrentState(): State
    {
        return $this->history->getCurrentState();
    }

    public function isCompleted(): bool
    {
        return $this->status->isCompleted();
    }

    public function getGateEvaluations(): GateResultCollection
    {
        return $this->history->getGateEvaluations();
    }
}
```

#### 2.2 Sub-Component: ExecutionHistory

**Responsibility:** Track what has been executed

```php
class ExecutionHistory
{
    private GateResultCollection $gateEvaluations;
    private ActionResultCollection $actionExecutions;
    private ActionSkipCollection $actionSkips;
    private ?State $currentState;

    public function __construct(State $initialState)
    {
        $this->currentState = $initialState;
        $this->gateEvaluations = new GateResultCollection();
        $this->actionExecutions = new ActionResultCollection();
        $this->actionSkips = new ActionSkipCollection();
    }

    public function recordGateEvaluation(Gate $gate, GateResult $result, bool $isActionGate): void
    {
        $this->gateEvaluations->add(new GateEvaluation($gate, $result, $isActionGate));
    }

    public function recordActionExecution(ActionResult $result): void
    {
        $this->actionExecutions->add($result);

        // Update current state if action returned new state
        if ($result->newState !== null) {
            $this->currentState = $result->newState;
        }
    }

    public function recordActionSkip(Action $action, GateResult $gateResult): void
    {
        $this->actionSkips->add(new ActionSkip($action, $gateResult));
    }

    public function getCurrentState(): State
    {
        return $this->currentState;
    }

    public function getGateEvaluations(): GateResultCollection
    {
        return $this->gateEvaluations;
    }

    public function getActionExecutions(): ActionResultCollection
    {
        return $this->actionExecutions;
    }

    public function getActionSkips(): ActionSkipCollection
    {
        return $this->actionSkips;
    }
}
```

#### 2.3 Sub-Component: ExecutionStatus

**Responsibility:** Track workflow state (completed, paused, stopped, etc.)

```php
class ExecutionStatus
{
    private bool $completed = false;
    private bool $paused = false;
    private bool $stopped = false;
    private bool $skippedDueToLock = false;
    private mixed $metadata = null;

    public function markCompleted(): void
    {
        $this->completed = true;
    }

    public function markPaused(mixed $metadata = null): void
    {
        $this->paused = true;
        $this->metadata = $metadata;
    }

    public function markStopped(mixed $metadata = null): void
    {
        $this->stopped = true;
        $this->metadata = $metadata;
    }

    public function markSkippedDueToLock(): void
    {
        $this->skippedDueToLock = true;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    public function wasSkippedDueToLock(): bool
    {
        return $this->skippedDueToLock;
    }

    public function getMetadata(): mixed
    {
        return $this->metadata;
    }
}
```

#### 2.4 Value Objects for History Items

```php
// Represents a single gate evaluation in history
readonly class GateEvaluation
{
    public function __construct(
        public Gate $gate,
        public GateResult $result,
        public bool $isActionGate,
        public float $timestamp = 0.0,
    ) {
        if ($this->timestamp === 0.0) {
            $this->timestamp = microtime(true);
        }
    }
}

// Represents a skipped action
readonly class ActionSkip
{
    public function __construct(
        public Action $action,
        public GateResult $gateResult,
        public float $timestamp = 0.0,
    ) {
        if ($this->timestamp === 0.0) {
            $this->timestamp = microtime(true);
        }
    }
}
```

### Benefits of Decomposition

**Clarity:**
- ✅ Each class has single responsibility
- ✅ Easier to understand what each part does
- ✅ Easier to test in isolation

**Maintainability:**
- ✅ Changes to history tracking don't affect status logic
- ✅ Can add new features without bloating main context
- ✅ Easier to reason about serialization (serialize each component)

**Flexibility:**
- ✅ Can swap implementations (e.g., different history storage)
- ✅ Can add features per component (e.g., history filtering)
- ✅ Easier to extend without breaking existing code

### Considerations

**Complexity:**
- ⚠️ More classes to understand
- ⚠️ More indirection (delegates to sub-components)
- 💭 Is this over-engineering for current needs?

**Serialization:**
- 💭 How does serialization work with decomposed structure?
- 💭 Do we serialize the whole context or just parts?

**Performance:**
- 💭 Does delegation add noticeable overhead?
- 💭 Should collections be lazy-loaded?

---

## 3. Additional Considerations

### 3.1 Immutability vs Mutability

**Current State:** TransitionContext is mutable (adds action results)

**Question:** Should we move toward immutability?

**Option A: Immutable Context (Functional Approach)**
```php
class TransitionContext
{
    public function withActionResult(ActionResult $result): self
    {
        $new = clone $this;
        $new->history = $this->history->withActionResult($result);
        return $new;
    }
}
```

**Benefits:**
- ✅ Thread-safe
- ✅ Easier to reason about
- ✅ No accidental mutations

**Drawbacks:**
- ⚠️ Memory overhead (cloning)
- ⚠️ More verbose usage

**Recommendation:** Keep mutable for now
- Context is built up during execution
- Immutability adds complexity without clear benefits
- Can revisit if concurrency becomes a concern

### 3.2 Builder Pattern for Context

If context becomes complex, consider builder:

```php
class TransitionContextBuilder
{
    private State $initialState;
    private Delta $delta;
    private ExecutionHistory $history;
    private ExecutionStatus $status;
    private ?LockState $lockState = null;

    public static function create(State $initialState, Delta $delta): self
    {
        return new self($initialState, $delta);
    }

    public function withLock(LockState $lockState): self
    {
        $this->lockState = $lockState;
        return $this;
    }

    public function build(): TransitionContext
    {
        return new TransitionContext(
            $this->initialState,
            $this->delta,
            $this->history,
            $this->status,
            $this->lockState
        );
    }
}
```

### 3.3 Value Objects for State Changes

Consider making state changes first-class:

```php
readonly class StateChange
{
    public function __construct(
        public State $from,
        public State $to,
        public Action $causedBy,
        public float $timestamp,
    ) {}
}

// In ExecutionHistory:
private StateChangeCollection $stateChanges;
```

### 3.4 Validation Layer

Where should validation live?

**Option A: In Value Objects**
```php
readonly class Delta
{
    public function __construct(private array $changes)
    {
        $this->validate();
    }

    private function validate(): void
    {
        foreach ($this->changes as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Delta keys must be strings');
            }
        }
    }
}
```

**Option B: Separate Validator**
```php
interface DeltaValidator
{
    public function validate(Delta $delta): ValidationResult;
}
```

**Recommendation:** Start with Option A (validation in constructor)
- Fail fast
- Ensures object is always valid
- Can add separate validators later for complex rules

### 3.5 Event Sourcing Considerations

If we want to reconstruct context from events:

```php
class TransitionContext
{
    /** @var Event[] */
    private array $events = [];

    public function recordEvent(Event $event): void
    {
        $this->events[] = $event;
        $this->apply($event);
    }

    private function apply(Event $event): void
    {
        match($event::class) {
            ActionExecuted::class => $this->history->recordActionExecution($event->result),
            GateEvaluated::class => $this->history->recordGateEvaluation(...),
            // etc.
        };
    }

    public static function fromEvents(array $events): self
    {
        $context = new self(...);
        foreach ($events as $event) {
            $context->apply($event);
        }
        return $context;
    }
}
```

---

## 4. Migration Strategy

### Phase 1: Add Types Without Breaking Changes
1. Introduce `Delta` as value object
2. Add `*Collection` classes
3. Keep array-based APIs for backward compatibility
4. Mark arrays as deprecated

### Phase 2: Add Sub-Components
1. Introduce `ExecutionHistory` and `ExecutionStatus`
2. Have `TransitionContext` delegate to them internally
3. Keep existing public API
4. Add new methods that return sub-components

### Phase 3: Breaking Changes (Major Version)
1. Remove array-based APIs
2. Require `Delta` instead of array
3. Return collections instead of arrays
4. Update all method signatures

### Phase 4: Advanced Features
1. Add validation layer
2. Consider event sourcing if needed
3. Optimize performance based on real usage

---

## 5. Open Questions

1. **Delta Interface vs Value Object?**
   - Start with value object
   - Can add interface later if multiple implementations needed

2. **Immutable Collections?**
   - Should collections be immutable (return new instance on add)?
   - Or mutable (modify in place)?
   - Recommendation: Mutable for builder pattern, immutable for public API

3. **Serialization Format?**
   - JSON? PHP serialize? Custom format?
   - How do we handle Action/Gate instances (can't serialize closures)?
   - Recommendation: JSON with action/gate class names + ActionFactory/GateFactory

4. **Performance Impact?**
   - Does decomposition add overhead?
   - Should we benchmark with large workflows?
   - Recommendation: Implement and measure, optimize if needed

5. **Versioning Strategy?**
   - How do we handle serialized contexts across versions?
   - Do we need version field in serialization?
   - Recommendation: Yes, add version field from the start

6. **Testing Strategy?**
   - Do we need unit tests for each collection class?
   - Integration tests for decomposed context?
   - Recommendation: Yes to both

---

## 6. Recommendations Summary

### High Priority (Do Soon)
1. ✅ **Introduce Delta value object** - improves type safety, easy to add
2. ✅ **Create specific collection classes** - GateCollection, ActionCollection, etc.
3. ✅ **Add ExecutionHistory sub-component** - reduces context complexity

### Medium Priority (Next Version)
4. 🔄 **Add ExecutionStatus sub-component** - complete the decomposition
5. 🔄 **Introduce value objects** - GateEvaluation, ActionSkip, StateChange
6. 🔄 **Add validation in constructors** - fail fast on invalid data

### Low Priority (Consider Later)
7. 💭 **Builder pattern** - if context construction becomes complex
8. 💭 **Event sourcing** - if we need to reconstruct from events
9. 💭 **Immutable context** - if concurrency becomes important

### Not Recommended (Avoid)
❌ External collection libraries - adds dependencies
❌ Full immutability now - adds complexity without clear benefit
❌ Over-engineered validation - start simple

---

## 7. Example: Before and After

### Before (Current)
```php
// Usage
$context = $stateFlow
    ->transition($currentState, ['status' => 'published'])
    ->execute();

// Inside Configuration
public function __construct(
    private array $transitionGates,  // @param Gate[]
    private array $actions,           // @param Action[]
)

// Inside TransitionContext
private array $actions = [];  // @var ActionResult[]
public function addActionResult(ActionResult $result): void
```

### After (Proposed)
```php
// Usage
$context = $stateFlow
    ->transition($currentState, new Delta(['status' => 'published']))
    ->execute();

// Inside Configuration
public function __construct(
    private GateCollection $transitionGates,
    private ActionCollection $actions,
)

// Inside TransitionContext
private ExecutionHistory $history;
private ExecutionStatus $status;

public function recordActionExecution(ActionResult $result): void
{
    $this->history->recordActionExecution($result);

    if ($result->executionState === ExecutionState::PAUSE) {
        $this->status->markPaused($result->metadata);
    }
}
```

---

## Conclusion

Both suggestions are excellent and align with SOLID principles:

1. **Delta as value object** - provides type safety and encapsulation
2. **Typed collections** - better than arrays with docblocks
3. **Decomposed TransitionContext** - follows Single Responsibility Principle

The key is to **introduce changes incrementally** to avoid breaking existing code and to validate each decision with real usage before moving forward.

Start with Delta and collections (easy wins), then tackle context decomposition (more complex but high value).
