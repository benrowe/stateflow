# Usage Examples

This document provides comprehensive examples of using StateFlow in various scenarios.

## Table of Contents

1. [Basic Usage](#basic-usage)
2. [E-Commerce Order Workflow](#e-commerce-order-workflow)
3. [Content Publishing System](#content-publishing-system)
4. [Async Workflow with Pause/Resume](#async-workflow-with-pauseresume)
5. [Step-Through Execution](#step-through-execution)
6. [Error Handling](#error-handling)
7. [Testing Patterns](#testing-patterns)

---

## Basic Usage

### Simple State Transition

```php
use BenRowe\StateFlow\StateMachine;
use BenRowe\StateFlow\Configuration;

// 1. Define your state
class ArticleState implements State
{
    public function __construct(
        private string $status,
        private ?DateTimeImmutable $publishedAt = null,
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'publishedAt' => $this->publishedAt?->format('c'),
        ];
    }

    public function with(array $changes): State
    {
        return new self(
            status: $changes['status'] ?? $this->status,
            publishedAt: isset($changes['publishedAt'])
                ? new DateTimeImmutable($changes['publishedAt'])
                : $this->publishedAt,
        );
    }
}

// 2. Create configuration
$config = function(State $state, array $delta): Configuration {
    if (isset($delta['status']) && $delta['status'] === 'published') {
        return new Configuration(
            transitionGates: [new CanPublishGate()],
            actions: [new PublishAction()],
        );
    }

    return new Configuration();
};

// 3. Create machine
$machine = new StateMachine(
    configProvider: $config,
);

// 4. Execute transition
$state = new ArticleState('draft');
$worker = $machine->transition($state, ['status' => 'published']);
$context = $worker->execute();

// 5. Check result
if ($context->isCompleted()) {
    echo "Published successfully!\n";
    $finalState = $context->getCurrentState();
}
```

---

## E-Commerce Order Workflow

### Complete Order State Machine

```php
// State definition
class OrderState implements State
{
    public function __construct(
        private string $id,
        private string $status,
        private float $total,
        private ?string $paymentId = null,
        private ?DateTimeImmutable $shippedAt = null,
        private array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'total' => $this->total,
            'paymentId' => $this->paymentId,
            'shippedAt' => $this->shippedAt?->format('c'),
            'metadata' => $this->metadata,
        ];
    }

    public function with(array $changes): State
    {
        return new self(
            id: $changes['id'] ?? $this->id,
            status: $changes['status'] ?? $this->status,
            total: $changes['total'] ?? $this->total,
            paymentId: $changes['paymentId'] ?? $this->paymentId,
            shippedAt: isset($changes['shippedAt'])
                ? new DateTimeImmutable($changes['shippedAt'])
                : $this->shippedAt,
            metadata: isset($changes['metadata'])
                ? array_merge($this->metadata, $changes['metadata'])
                : $this->metadata,
        );
    }

    public function getId(): string { return $this->id; }
    public function getStatus(): string { return $this->status; }
}

// Gates
class HasPaymentGate implements Gate
{
    public function evaluate(GateContext $context): GateResult
    {
        $state = $context->currentState->toArray();
        return !empty($state['paymentId'])
            ? GateResult::ALLOW
            : GateResult::DENY;
    }

    public function message(): ?string
    {
        return 'Order must have payment before fulfillment';
    }
}

class HasInventoryGate implements Gate
{
    public function __construct(private InventoryService $inventory) {}

    public function evaluate(GateContext $context): GateResult
    {
        $state = $context->currentState->toArray();
        $available = $this->inventory->check($state['id']);

        return $available
            ? GateResult::ALLOW
            : GateResult::DENY;
    }

    public function message(): ?string
    {
        return 'Insufficient inventory';
    }
}

// Actions
class ChargePaymentAction implements Action
{
    public function __construct(private PaymentGateway $gateway) {}

    public function execute(ActionContext $context): ActionResult
    {
        $state = $context->currentState->toArray();

        try {
            $charge = $this->gateway->charge($state['total']);

            $newState = $context->currentState->with([
                'paymentId' => $charge->id,
                'metadata' => ['chargedAt' => now()],
            ]);

            return ActionResult::continue($newState);

        } catch (PaymentException $e) {
            return ActionResult::stop(metadata: [
                'error' => 'Payment failed: ' . $e->getMessage(),
            ]);
        }
    }
}

class ReserveInventoryAction implements Action, Guardable
{
    public function __construct(private InventoryService $inventory) {}

    public function gate(): Gate
    {
        return new HasInventoryGate($this->inventory);
    }

    public function execute(ActionContext $context): ActionResult
    {
        $state = $context->currentState->toArray();
        $this->inventory->reserve($state['id']);

        return ActionResult::continue();
    }
}

class ShipOrderAction implements Action
{
    public function __construct(private ShippingService $shipping) {}

    public function execute(ActionContext $context): ActionResult
    {
        $state = $context->currentState->toArray();

        // Initiate shipping (async)
        $shipment = $this->shipping->create($state['id']);

        $newState = $context->currentState->with([
            'status' => 'shipped',
            'shippedAt' => new DateTimeImmutable(),
            'metadata' => ['shipmentId' => $shipment->id],
        ]);

        return ActionResult::continue($newState);
    }
}

// Configuration
class OrderConfigurationProvider implements ConfigurationProvider
{
    public function __construct(
        private PaymentGateway $payment,
        private InventoryService $inventory,
        private ShippingService $shipping,
    ) {}

    public function provide(State $currentState, array $desiredDelta): Configuration
    {
        if (!isset($desiredDelta['status'])) {
            return new Configuration();
        }

        return match ($desiredDelta['status']) {
            'processing' => new Configuration(
                transitionGates: [new HasPaymentGate()],
                actions: [
                    new ChargePaymentAction($this->payment),
                    new ReserveInventoryAction($this->inventory),
                ],
            ),
            'shipped' => new Configuration(
                transitionGates: [
                    new HasPaymentGate(),
                    new HasInventoryGate($this->inventory),
                ],
                actions: [new ShipOrderAction($this->shipping)],
            ),
            'cancelled' => new Configuration(
                actions: [
                    new RefundPaymentAction($this->payment),
                    new ReleaseInventoryAction($this->inventory),
                ],
            ),
            default => new Configuration(),
        };
    }
}

// Usage
$machine = new StateMachine(
    configProvider: new OrderConfigurationProvider($payment, $inventory, $shipping),
    eventDispatcher: new OrderEventDispatcher(),
    lockProvider: new RedisLockProvider($redis),
    lockKeyProvider: new class implements LockKeyProvider {
        public function getLockKey(State $state, array $delta): string {
            return "order:{$state->toArray()['id']}";
        }
    },
);

// Process order with lock
try {
    $state = new OrderState(
        id: 'ORD-12345',
        status: 'pending',
        total: 99.99,
    );
    $worker = $machine->transition($state, ['status' => 'processing']);
    $context = $worker->execute();

    if ($context->isCompleted()) {
        echo "Order processed successfully\n";
    } elseif ($context->isStopped()) {
        $metadata = $context->getStatusMetadata();
        echo "Order processing failed: {$metadata['error']}\n";
    }

} catch (LockAcquisitionException $e) {
    echo "Order is already being processed\n";
}
```

---

## Content Publishing System

```php
class ContentState implements State
{
    public function __construct(
        private string $id,
        private string $status,
        private string $content,
        private array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'content' => $this->content,
            'metadata' => $this->metadata,
        ];
    }

    public function with(array $changes): State
    {
        return new self(
            id: $changes['id'] ?? $this->id,
            status: $changes['status'] ?? $this->status,
            content: $changes['content'] ?? $this->content,
            metadata: isset($changes['metadata'])
                ? array_merge($this->metadata, $changes['metadata'])
                : $this->metadata,
        );
    }
}

// Publishing workflow
$machine = new StateMachine(
    configProvider: function(State $state, array $delta): Configuration {
        if (isset($delta['status']) && $delta['status'] === 'published') {
            return new Configuration(
                transitionGates: [
                    new HasContentGate(),
                    new HasTitleGate(),
                    new UserCanPublishGate($user),
                ],
                actions: [
                    new SetPublishDateAction(),
                    new GenerateSEOMetaAction(),
                    new GenerateThumbnailsAction(), // Async - will pause
                    new InvalidateCacheAction(),
                    new NotifySubscribersAction(),
                ],
            );
        }

        return new Configuration();
    },
    eventDispatcher: new PublishingEventDispatcher(),
);

// Publish with automatic pause for thumbnails
$state = new ContentState('article-1', 'draft', 'Content here');
$worker = $machine->transition($state, ['status' => 'published']);
$context = $worker->execute();

if ($context->isPaused()) {
    // Thumbnails generating...
    $metadata = $context->getStatusMetadata();
    echo "Paused for thumbnail generation: Job {$metadata['jobId']}\n";

    // Store context
    Cache::put("workflow:{$state->toArray()['id']}", $context->serialize(), 3600);
}

// Later, when thumbnail job completes:
public function onThumbnailsComplete(string $contentId)
{
    $serializedContext = Cache::get("workflow:{$contentId}");
    $context = TransitionContext::unserialize($serializedContext, $stateFactory, $actionFactory);

    $worker = $machine->fromContext($context);
    $finalContext = $worker->execute();
    // Continues to cache invalidation and notifications
}
```

---

## Async Workflow with Pause/Resume

```php
// Long-running action that pauses
class ProcessVideoAction implements Action
{
    public function __construct(private VideoProcessor $processor) {}

    public function execute(ActionContext $context): ActionResult
    {
        $state = $context->currentState->toArray();

        // Kick off async video processing
        $job = $this->processor->processAsync($state['videoUrl']);

        // Pause until job completes
        return ActionResult::pause(metadata: [
            'jobId' => $job->id,
            'reason' => 'Waiting for video processing',
            'estimatedDuration' => 300, // 5 minutes
        ]);
    }
}

// Main workflow
$machine = new StateMachine(
    configProvider: fn($s, $d) => new Configuration(
        actions: [
            new ProcessVideoAction($processor),
            new GenerateSubtitlesAction(), // Runs after resume
            new PublishVideoAction(),
        ],
    ),
    lockProvider: new RedisLockProvider($redis),
    lockKeyProvider: new EntityLockKeyProvider(),
);

// Start workflow
$state = new VideoState('pending');
$worker = $machine->transition($state, ['status' => 'processing']);
$context = $worker->execute();

if ($context->isPaused()) {
    $metadata = $context->getStatusMetadata();

    // Store serialized context in database
    DB::table('workflow_states')->insert([
        'entity_id' => $video->id,
        'serialized_context' => $context->serialize(),
        'paused_at' => now(),
        'resume_after' => $metadata['estimatedDuration'],
    ]);

    // Queue resume job
    ProcessWorkflowResume::dispatch($video->id)
        ->delay($metadata['estimatedDuration']);
}

// Resume job handler
class ProcessWorkflowResume
{
    public function handle(string $videoId)
    {
        $row = DB::table('workflow_states')
            ->where('entity_id', $videoId)
            ->first();

        $context = TransitionContext::unserialize(
            $row->serialized_context,
            $this->stateFactory,
            $this->actionFactory
        );

        try {
            $machine = $this->buildMachine();
            $worker = $machine->fromContext($context);
            $finalContext = $worker->execute();

            if ($finalContext->isCompleted()) {
                DB::table('workflow_states')->where('entity_id', $videoId)->delete();
                Log::info("Video workflow completed: {$videoId}");
            }

        } catch (LockLostException $e) {
            Log::error("Lock lost for video workflow: {$videoId}");
            // Handle recovery...
        }
    }
}
```

---

## Step-Through Execution

```php
// Execute actions one at a time
$machine = new StateMachine(
    configProvider: fn($s, $d) => new Configuration(
        actions: [
            new Action1(),
            new Action2(),
            new Action3(),
        ],
    ),
);

$state = new ArticleState('draft');
$worker = $machine->transition($state, ['status' => 'published']);

// Run gates first
$gateResult = $worker->runGates();

// Execute Action1, then pause
$context = $worker->runNextAction();
echo "After first action: " . $context->getCurrentState()->toArray()['step'] . "\n";

// Execute Action2, then pause
$context = $worker->runNextAction();
echo "After second action: " . $context->getCurrentState()->toArray()['step'] . "\n";

// Execute Action3, completes
$context = $worker->runNextAction();
if ($context->isCompleted()) {
    echo "All actions completed!\n";
}

// Inspect execution trace
foreach ($context->getActionExecutions() as $exec) {
    echo "Executed: {$exec['action']} at {$exec['timestamp']}\n";
}
```

---

## Error Handling

### Gate Failures

```php
$worker = $machine->transition($state, ['status' => 'published']);
$context = $worker->execute();

if ($context->isStopped()) {
    // Check which gate failed
    foreach ($context->getGateEvaluations() as $eval) {
        if ($eval['result'] === 'DENY') {
            echo "Gate failed: {$eval['gate']}\n";
            echo "Reason: {$eval['message']}\n";
        }
    }
}
```

### Action Errors

```php
class SafeAction implements Action
{
    public function execute(ActionContext $context): ActionResult
    {
        try {
            // Risky operation
            $this->doSomething();

            return ActionResult::continue();

        } catch (\Exception $e) {
            // Stop the transition
            return ActionResult::stop(metadata: [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
```

### Lock Errors

```php
try {
    $worker = $machine->transition($state, ['status' => 'published']);
    $context = $worker->execute();

} catch (LockAcquisitionException $e) {
    // Another process holds the lock
    Log::warning('Lock contention', ['entity' => $entity->id]);

    // Retry with exponential backoff
    retry(3, function() use ($machine, $state) {
        $worker = $machine->transition($state, ['status' => 'published']);
        return $worker->execute();
    }, 1000);
}
```

### Exception Handling

```php
try {
    $worker = $machine->transition($state, ['status' => 'published']);
    $context = $worker->execute();

} catch (LockAcquisitionException $e) {
    return response()->json(['error' => 'Resource locked'], 409);

} catch (LockLostException $e) {
    Log::error('Lock was lost during execution');
    $worker->releaseLock();
    return response()->json(['error' => 'Workflow interrupted'], 500);

} catch (\Throwable $e) {
    // Always release lock on unexpected errors
    $worker->releaseLock();

    Log::error('Transition failed', [
        'exception' => $e->getMessage(),
        'state' => $worker->getContext()->getCurrentState()->toArray(),
    ]);

    throw $e;
}
```

---

## Testing Patterns

### Basic Test

```php
use PHPUnit\Framework\TestCase;

class OrderWorkflowTest extends TestCase
{
    public function test_order_can_be_processed()
    {
        $machine = new StateMachine(
            configProvider: new OrderConfigurationProvider(
                payment: $this->mockPayment(),
                inventory: $this->mockInventory(),
                shipping: $this->mockShipping(),
            ),
        );

        $state = new OrderState('ORD-123', 'pending', 99.99);
        $worker = $machine->transition($state, ['status' => 'processing']);
        $context = $worker->execute();

        $this->assertTrue($context->isCompleted());
        $this->assertEquals('processing', $context->getCurrentState()->toArray()['status']);
    }
}
```

### Testing Gates

```php
public function test_cannot_publish_without_content()
{
    $machine = new StateMachine(
        configProvider: fn($s, $d) => new Configuration(
            transitionGates: [new HasContentGate()],
        ),
    );

    $state = new ArticleState('draft', content: '');
    $worker = $machine->transition($state, ['status' => 'published']);
    $context = $worker->execute();

    $this->assertTrue($context->isStopped());

    $gateEvals = $context->getGateEvaluations();
    $this->assertEquals('DENY', $gateEvals[0]['result']);
    $this->assertEquals(HasContentGate::class, $gateEvals[0]['gate']);
}

public function test_idempotent_transition_succeeds_without_running_actions()
{
    $actionExecuted = false;

    $machine = new StateMachine(
        configProvider: fn($s, $d) => new Configuration(
            transitionGates: [new AlreadyInStateGate()],
            actions: [new class($actionExecuted) implements Action {
                public function __construct(private bool &$executed) {}

                public function execute(ActionContext $context): ActionResult {
                    $this->executed = true;
                    return ActionResult::continue();
                }
            }],
        ),
    );

    // Already in 'published' state
    $state = new ArticleState('published');
    $worker = $machine->transition($state, ['status' => 'published']);
    $context = $worker->execute();

    // Transition completes successfully
    $this->assertTrue($context->isCompleted());

    // But action was NOT executed (skipped)
    $this->assertFalse($actionExecuted);

    // Gate returned SKIP_IDEMPOTENT
    $gateEvals = $context->getGateEvaluations();
    $this->assertEquals('SKIP_IDEMPOTENT', $gateEvals[0]['result']);
}
```

### Testing Actions

```php
public function test_action_updates_state()
{
    $action = new SetPublishDateAction();

    $context = new ActionContext(
        currentState: new ArticleState('draft'),
        desiredDelta: ['status' => 'published'],
        executionContext: $this->createMock(TransitionContext::class),
    );

    $result = $action->execute($context);

    $this->assertEquals(ExecutionState::CONTINUE, $result->executionState);
    $this->assertNotNull($result->newState->toArray()['publishedAt']);
}
```

### Testing Lock Contention

```php
public function test_concurrent_transitions_prevented()
{
    $lockProvider = new InMemoryLockProvider();

    $machine1 = $this->buildMachine($lockProvider);
    $machine2 = $this->buildMachine($lockProvider);

    $state = new OrderState('ORD-123', 'pending', 99.99);

    // Machine 1 acquires lock
    $worker1 = $machine1->transition($state, ['status' => 'published']);
    $context1 = $worker1->execute();
    $this->assertTrue($context1->getLockState()->isLocked());

    // Machine 2 fails to acquire lock
    $this->expectException(LockAcquisitionException::class);
    $worker2 = $machine2->transition($state, ['status' => 'published']);
    $worker2->execute();
}
```

### Testing Pause/Resume

```php
public function test_workflow_can_pause_and_resume()
{
    $machine = new StateMachine(
        configProvider: fn($s, $d) => new Configuration(
            actions: [
                new PausingAction(),
                new FinalAction(),
            ],
        ),
    );

    $state = new VideoState('pending');
    $worker = $machine->transition($state, ['status' => 'processing']);
    $context = $worker->execute();
    $this->assertTrue($context->isPaused());

    // Serialize
    $serialized = $context->serialize();

    // Deserialize and resume
    $restoredContext = TransitionContext::unserialize(
        $serialized,
        $this->stateFactory,
        $this->actionFactory
    );
    $resumedWorker = $machine->fromContext($restoredContext);
    $finalContext = $resumedWorker->execute();

    $this->assertTrue($finalContext->isCompleted());
}
```

### Testing Events

```php
public function test_events_are_dispatched()
{
    $dispatcher = new TestEventDispatcher();

    $machine = new StateMachine(
        configProvider: $config,
        eventDispatcher: $dispatcher,
    );

    $state = new OrderState('ORD-123', 'pending', 99.99);
    $worker = $machine->transition($state, ['status' => 'processing']);
    $worker->execute();

    $dispatcher->assertDispatched(TransitionStarting::class);
    $dispatcher->assertDispatched(GateEvaluated::class);
    $dispatcher->assertDispatched(ActionExecuted::class);
    $dispatcher->assertDispatched(TransitionCompleted::class);
}
```
