# Flow Diagrams

Visual representations of StateFlow's architecture and execution flow.

## Table of Contents

1. [High-Level Execution Flow](#high-level-execution-flow)
2. [Detailed Transition Lifecycle](#detailed-transition-lifecycle)
3. [Lock Lifecycle](#lock-lifecycle)
4. [Gate Evaluation Flow](#gate-evaluation-flow)
5. [Action Execution Flow](#action-execution-flow)
6. [Pause and Resume Flow](#pause-and-resume-flow)
7. [Race Condition Prevention](#race-condition-prevention)
8. [Event Flow](#event-flow)

---

## High-Level Execution Flow

```mermaid
graph TD
    Start([User calls worker.execute])
    AcquireLock{Acquire Lock?}
    LockSuccess{Lock Acquired?}
    LoadConfig[Load Configuration<br/>via ConfigurationProvider]
    EvalGates[Evaluate Transition Gates]
    GatesPassed{All Gates ALLOW?}
    ExecActions[Execute Actions in Order]
    ActionResult{Action Result?}
    MarkComplete[Mark Transition Complete]
    ReleaseLock[Release Lock]
    ReturnContext[Return TransitionContext]

    Start --> AcquireLock
    AcquireLock -->|Lock Configured| LockSuccess
    AcquireLock -->|No Lock| LoadConfig
    LockSuccess -->|Yes| LoadConfig
    LockSuccess -->|No - FAIL_FAST| ReturnContext
    LockSuccess -->|No - SKIP| ReturnContext

    LoadConfig --> EvalGates
    EvalGates --> GatesPassed
    GatesPassed -->|Yes| ExecActions
    GatesPassed -->|No| ReleaseLock

    ExecActions --> ActionResult
    ActionResult -->|CONTINUE| ExecActions
    ActionResult -->|PAUSE| ReturnContext
    ActionResult -->|STOP| ReleaseLock
    ActionResult -->|All Complete| MarkComplete

    MarkComplete --> ReleaseLock
    ReleaseLock --> ReturnContext

    style Start fill:#e1f5e1
    style ReturnContext fill:#e1f5e1
    style AcquireLock fill:#fff4e1
    style GatesPassed fill:#ffe1e1
    style ActionResult fill:#ffe1e1
```

---

## Detailed Transition Lifecycle

```mermaid
stateDiagram-v2
    [*] --> Initializing: worker.execute()

    Initializing --> LockAcquisition: Event: TransitionStarting

    LockAcquisition --> ConfigurationLoading: Lock Acquired
    LockAcquisition --> Failed: Lock Failed

    ConfigurationLoading --> TransitionGateEvaluation: Config Loaded

    TransitionGateEvaluation --> TransitionGateEvaluation: Evaluate Each Gate<br/>Event: GateEvaluating/GateEvaluated
    TransitionGateEvaluation --> ActionExecution: All Gates ALLOW
    TransitionGateEvaluation --> Stopped: Any Gate DENY

    ActionExecution --> ActionGateCheck: Next Action

    ActionGateCheck --> ActionGateEvaluation: Action has Gate
    ActionGateCheck --> ExecuteAction: No Gate

    ActionGateEvaluation --> ExecuteAction: Gate ALLOW
    ActionGateEvaluation --> ActionExecution: Gate DENY (skip action)

    ExecuteAction --> ActionExecution: Result: CONTINUE
    ExecuteAction --> Paused: Result: PAUSE
    ExecuteAction --> Stopped: Result: STOP
    ExecuteAction --> Completed: No More Actions

    Completed --> LockRelease: Event: TransitionCompleted
    Stopped --> LockRelease: Event: TransitionStopped
    Paused --> [*]: Lock Held<br/>Event: TransitionPaused

    LockRelease --> [*]
    Failed --> [*]: Event: TransitionFailed

    note right of Paused
        Lock persists!
        Context serializable
        Can resume later
    end note

    note right of LockRelease
        Lock released on:
        - Completed
        - Stopped
        - Failed
    end note
```

---

## Lock Lifecycle

```mermaid
sequenceDiagram
    participant User
    participant Machine
    participant Worker as StateWorker
    participant LockProvider
    participant Context
    participant Database

    User->>Machine: transition(state, delta)
    activate Machine
    Machine-->>User: StateWorker
    deactivate Machine

    User->>Worker: execute()
    activate Worker

    Worker->>LockProvider: acquire(lockKey, ttl)
    activate LockProvider

    alt Lock Available
        LockProvider-->>Worker: true (lock acquired)
        Note over Worker: Lock held in memory
        Worker->>Worker: Execute transition

        alt Transition Completes
            Worker->>Context: markCompleted()
            Worker->>LockProvider: release(lockKey)
            LockProvider-->>Worker: true
            Worker-->>User: Context (completed)
        else Transition Pauses
            Worker->>Context: markPaused()
            Note over Worker: Lock NOT released
            Worker->>Context: setLockState(lockKey, ttl)
            Worker-->>User: Context (paused, lock held)
            User->>Database: save(context.serialize())

            Note over Database: Hours/days later...

            User->>Database: load context
            Database-->>User: serialized context
            User->>Machine: fromContext(context)
            Machine-->>User: new StateWorker
            User->>Worker: execute()

            Worker->>LockProvider: exists(lockKey)
            alt Lock Still Exists
                LockProvider-->>Worker: true
                Worker->>Worker: Continue execution
                Worker->>LockProvider: release(lockKey)
                Worker-->>User: Context (completed)
            else Lock Lost/Expired
                LockProvider-->>Worker: false
                Worker-->>User: LockLostException
            end
        end
    else Lock Held by Another Process
        LockProvider-->>Worker: false

        alt Strategy: FAIL_FAST
            Worker-->>User: LockAcquisitionException
        else Strategy: SKIP
            Worker-->>User: Context (skipped)
        else Strategy: WAIT
            Worker->>LockProvider: retry acquire()
            Note over Worker,LockProvider: Retry until timeout
        end
    end

    deactivate LockProvider
    deactivate Worker
```

---

## Gate Evaluation Flow

```mermaid
flowchart TD
    Start([Gate Evaluation Starts])
    GateType{Gate Type?}

    TransitionGate[Transition Gate]
    ActionGate[Action Gate]

    CreateContext[Create GateContext<br/>currentState + desiredDelta]

    DispatchBefore[Dispatch: GateEvaluating]
    Evaluate[Call gate.evaluate()]
    DispatchAfter[Dispatch: GateEvaluated]

    RecordTrace[Record in TransitionContext]

    CheckResult{Result?}

    TransitionDeny[Stop Transition<br/>No actions run]
    ActionDeny[Skip This Action<br/>Continue to next]
    Allow[Continue]

    Start --> GateType
    GateType -->|Transition| TransitionGate
    GateType -->|Action| ActionGate

    TransitionGate --> CreateContext
    ActionGate --> CreateContext

    CreateContext --> DispatchBefore
    DispatchBefore --> Evaluate
    Evaluate --> DispatchAfter
    DispatchAfter --> RecordTrace
    RecordTrace --> CheckResult

    CheckResult -->|ALLOW| Allow
    CheckResult -->|DENY + Transition| TransitionDeny
    CheckResult -->|DENY + Action| ActionDeny

    style TransitionDeny fill:#ffe1e1
    style ActionDeny fill:#fff4e1
    style Allow fill:#e1f5e1
    style DispatchBefore fill:#e1e8ff
    style DispatchAfter fill:#e1e8ff
```

---

## Action Execution Flow

```mermaid
flowchart TD
    Start([Action Execution Starts])

    HasGate{Action implements<br/>Guardable?}
    EvalGate[Evaluate Action Gate]
    GateResult{Gate Result?}

    CreateContext[Create ActionContext<br/>currentState + desiredDelta<br/>+ executionContext]

    DispatchBefore[Dispatch: ActionExecuting]
    Execute[Call action.execute]
    DispatchAfter[Dispatch: ActionExecuted]

    UpdateState{New State<br/>returned?}
    ApplyState[Update context.currentState]

    RecordTrace[Record in TransitionContext]

    CheckExecState{Execution<br/>State?}

    Continue[Continue to Next Action]
    Pause[Pause Execution<br/>Lock held]
    Stop[Stop Execution<br/>Release lock]

    Skip[Skip Action<br/>Dispatch: ActionSkipped]

    Start --> HasGate
    HasGate -->|Yes| EvalGate
    HasGate -->|No| CreateContext

    EvalGate --> GateResult
    GateResult -->|ALLOW| CreateContext
    GateResult -->|DENY| Skip

    CreateContext --> DispatchBefore
    DispatchBefore --> Execute
    Execute --> DispatchAfter

    DispatchAfter --> UpdateState
    UpdateState -->|Yes| ApplyState
    UpdateState -->|No| RecordTrace
    ApplyState --> RecordTrace

    RecordTrace --> CheckExecState
    CheckExecState -->|CONTINUE| Continue
    CheckExecState -->|PAUSE| Pause
    CheckExecState -->|STOP| Stop

    Skip --> Continue

    style Continue fill:#e1f5e1
    style Pause fill:#fff4e1
    style Stop fill:#ffe1e1
    style Skip fill:#f0f0f0
    style DispatchBefore fill:#e1e8ff
    style DispatchAfter fill:#e1e8ff
```

---

## Pause and Resume Flow

```mermaid
sequenceDiagram
    participant User
    participant Machine
    participant Worker as StateWorker
    participant Action1
    participant Action2
    participant Action3
    participant Storage

    rect rgb(240, 248, 255)
        Note over User,Action3: Initial Transition
        User->>Machine: transition(state, ['status' => 'published'])
        Machine-->>User: StateWorker
        User->>Worker: execute()
        activate Worker

        Worker->>Action1: execute()
        activate Action1
        Action1-->>Worker: ActionResult::continue(newState)
        deactivate Action1
        Note over Worker: State updated

        Worker->>Action2: execute()
        activate Action2
        Note over Action2: Async operation needed<br/>(e.g., video processing)
        Action2-->>Worker: ActionResult::pause(metadata: {jobId: 123})
        deactivate Action2

        Note over Worker: Context marked as PAUSED<br/>Lock STILL HELD
        Worker-->>User: TransitionContext (paused=true)
        deactivate Worker

        User->>Storage: save(context.serialize())
        Note over Storage: Stores:<br/>- Current state<br/>- Remaining actions<br/>- Lock state<br/>- Execution trace
    end

    rect rgb(255, 250, 240)
        Note over User,Action3: Hours/Days Later
        Note over Storage: External event triggers resume<br/>(e.g., job completed)

        User->>Storage: load context
        Storage-->>User: serialized context

        User->>Machine: fromContext(context)
        Machine-->>User: new StateWorker
        User->>Worker: execute()
        activate Worker

        Note over Worker: Verify lock still held

        Worker->>Action3: execute()
        activate Action3
        Action3-->>Worker: ActionResult::continue(finalState)
        deactivate Action3

        Note over Worker: No more actions<br/>Mark as COMPLETED<br/>Release lock

        Worker-->>User: TransitionContext (completed=true)
        deactivate Worker
    end

    User->>Storage: delete context
```

---

## Race Condition Prevention

```mermaid
sequenceDiagram
    participant Process A
    participant Process B
    participant LockProvider as Lock Provider<br/>(Redis/DB)
    participant Order as Order State<br/>(status: draft)

    par Concurrent Requests
        Process A->>LockProvider: acquire("order:123")
        Process B->>LockProvider: acquire("order:123")
    end

    alt Process A acquires lock first
        LockProvider-->>Process A: true (lock acquired)
        LockProvider-->>Process B: false (lock held)

        rect rgb(230, 255, 230)
            Note over Process A: Process A proceeds
            Process A->>Process A: Evaluate gates
            Process A->>Process A: Execute actions
            Process A->>Order: Update status to "published"
            Process A->>LockProvider: release("order:123")
            Note over Process A: Transition complete
        end

        rect rgb(255, 230, 230)
            Note over Process B: Process B behavior depends on strategy

            alt Strategy: FAIL_FAST
                Process B->>Process B: Throw LockAcquisitionException
                Note over Process B: User receives 409 Conflict
            else Strategy: WAIT
                Process B->>LockProvider: retry acquire("order:123")
                Note over Process B: Wait up to timeout
                LockProvider-->>Process B: true (lock now available)
                Process B->>Process B: Execute transition
                Note over Process B: May fail if already published<br/>(idempotency check in gate)
            else Strategy: SKIP
                Process B->>Process B: Return context(skippedDueToLock=true)
                Note over Process B: User receives "already processing"
            end
        end
    end

    Note over Process A,Process B: Result: Only one process executes transition<br/>No duplicate state changes<br/>No race conditions
```

---

## Event Flow

```mermaid
graph TD
    subgraph "Transition Lifecycle Events"
        E1[TransitionStarting]
        E2[TransitionCompleted]
        E3[TransitionPaused]
        E4[TransitionStopped]
        E5[TransitionFailed]
    end

    subgraph "Gate Events"
        G1[GateEvaluating]
        G2[GateEvaluated]
    end

    subgraph "Action Events"
        A1[ActionExecuting]
        A2[ActionExecuted]
        A3[ActionSkipped]
    end

    subgraph "Lock Events"
        L1[LockAcquiring]
        L2[LockAcquired]
        L3[LockReleased]
        L4[LockFailed]
        L5[LockRestored]
        L6[LockLost]
    end

    subgraph "Event Dispatcher"
        D[EventDispatcher.dispatch]
    end

    subgraph "Event Handlers"
        H1[Logger]
        H2[Metrics Collector]
        H3[Audit Trail]
        H4[External Event Bus]
    end

    E1 --> D
    E2 --> D
    E3 --> D
    E4 --> D
    E5 --> D

    G1 --> D
    G2 --> D

    A1 --> D
    A2 --> D
    A3 --> D

    L1 --> D
    L2 --> D
    L3 --> D
    L4 --> D
    L5 --> D
    L6 --> D

    D --> H1
    D --> H2
    D --> H3
    D --> H4

    style D fill:#e1e8ff
    style H1 fill:#ffe1e1
    style H2 fill:#fff4e1
    style H3 fill:#e1ffe1
    style H4 fill:#ffe1ff
```

---

## Component Architecture

```mermaid
graph TB
    subgraph "Core Machine"
        SM[StateMachine]
        TC[TransitionContext]
    end

    subgraph "User-Provided Implementations"
        State[State Interface]
        CP[ConfigurationProvider]
        Config[Configuration]
    end

    subgraph "Gates"
        TG[Transition Gates]
        AG[Action Gates]
        Gate[Gate Interface]
    end

    subgraph "Actions"
        Action[Action Interface]
        Guardable[Guardable Interface]
        AR[ActionResult]
    end

    subgraph "Observability"
        ED[EventDispatcher]
        Events[Event Classes]
    end

    subgraph "Locking"
        LP[LockProvider]
        LKP[LockKeyProvider]
        LS[LockState]
    end

    SM -->|owns| TC
    SM -->|uses| State
    SM -->|calls| CP
    SM -->|dispatches to| ED
    SM -->|uses| LP
    SM -->|uses| LKP

    CP -->|returns| Config
    Config -->|contains| TG
    Config -->|contains| Action

    TG -.implements.-> Gate
    AG -.implements.-> Gate

    Action -.optionally implements.-> Guardable
    Guardable -->|provides| AG
    Action -->|returns| AR

    TC -->|stores| LS
    TC -->|tracks| State

    ED -->|publishes| Events

    SM -->|records in| TC

    style SM fill:#e1f5e1
    style TC fill:#fff4e1
    style State fill:#e1e8ff
    style Gate fill:#ffe1e1
    style Action fill:#ffe1ff
    style ED fill:#ffe1e1
```

---

## Complete Execution Timeline

```mermaid
gantt
    title StateFlow Execution Timeline
    dateFormat X
    axisFormat %L ms

    section Lock
    Acquire Lock           :lock1, 0, 10
    Lock Held             :active, lock2, 10, 190
    Release Lock          :lock3, 190, 200

    section Configuration
    Load Config           :config, 10, 20

    section Transition Gates
    Gate 1 Evaluate       :gate1, 20, 30
    Gate 2 Evaluate       :gate2, 30, 40

    section Actions
    Action 1 Execute      :action1, 40, 80
    Action 2 Execute      :action2, 80, 120
    Action 3 Execute      :action3, 120, 180

    section Events
    TransitionStarting    :milestone, event1, 0, 0
    GateEvaluating x2     :milestone, event2, 25, 25
    ActionExecuting x3    :milestone, event3, 60, 60
    TransitionCompleted   :milestone, event4, 190, 190

    section State
    State: draft          :state1, 0, 80
    State: processing     :active, state2, 80, 180
    State: published      :crit, state3, 180, 200
```

---

## State Machine Decision Tree

```mermaid
graph TD
    Start[worker.execute called]

    Start --> Lock{Lock<br/>Required?}
    Lock -->|Yes| TryLock{Can<br/>Acquire?}
    Lock -->|No| Gates

    TryLock -->|Yes| Gates[Evaluate Gates]
    TryLock -->|No - FAIL| Exception[Throw Exception]
    TryLock -->|No - SKIP| SkipCtx[Return Skipped Context]

    Gates --> GateLoop{More<br/>Gates?}
    GateLoop -->|Yes| EvalGate[Evaluate Next Gate]
    GateLoop -->|No| Actions

    EvalGate --> GateOk{ALLOW?}
    GateOk -->|Yes| GateLoop
    GateOk -->|No| StopGate[Stop - Gate Failed]

    Actions --> ActionLoop{More<br/>Actions?}
    ActionLoop -->|Yes| CheckGuard{Has<br/>Guard?}
    ActionLoop -->|No| Complete[Mark Completed]

    CheckGuard -->|Yes| EvalGuard[Evaluate Guard]
    CheckGuard -->|No| ExecAction

    EvalGuard --> GuardOk{ALLOW?}
    GuardOk -->|Yes| ExecAction[Execute Action]
    GuardOk -->|No| ActionLoop

    ExecAction --> ActionRes{Result?}
    ActionRes -->|CONTINUE| ActionLoop
    ActionRes -->|PAUSE| PauseCtx[Return Paused Context]
    ActionRes -->|STOP| StopAction[Stop - Action Stopped]

    Complete --> ReleaseLock[Release Lock]
    StopGate --> ReleaseLock
    StopAction --> ReleaseLock

    ReleaseLock --> Return[Return Context]
    PauseCtx --> Return
    SkipCtx --> Return
    Exception --> Return

    style Complete fill:#e1f5e1
    style PauseCtx fill:#fff4e1
    style StopGate fill:#ffe1e1
    style StopAction fill:#ffe1e1
    style Exception fill:#ffe1e1
```

---

## Notes

- All diagrams are in Mermaid format and can be rendered by GitHub, many Markdown editors, and documentation sites
- Diagrams are also viewable at [mermaid.live](https://mermaid.live) for interactive exploration
- The execution flow shows the complete lifecycle from user request to final context return
- Lock lifecycle diagram emphasizes lock persistence during pause
- Race condition diagram shows how concurrent requests are handled safely
