<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Locking\LockState;
use JsonException;

class TransitionContext
{
    /**
     * @var ActionResult[]
     */
    private array $actions = [];

    /**
     * @var GateEvaluation[]
     */
    private array $gateEvaluations = [];

    /**
     * @var ActionSkip[]
     */
    private array $actionSkips = [];

    private ?ExecutionState $status = null;

    private State $currentState;

    private LockState $lockState;

    private bool $skippedDueToLock = false;

    public function __construct(
        private readonly State $initialState,
        private readonly Delta $desiredDelta,
        private readonly Configuration $configuration,
    ) {
        $this->currentState = $initialState;
        $this->lockState = new LockState();
    }

    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    public function getCurrentState(): State
    {
        return $this->currentState;
    }

    public function getInitialState(): State
    {
        return $this->initialState;
    }

    public function updateCurrentState(State $newState): void
    {
        $this->currentState = $newState;
    }

    public function getDesiredDelta(): Delta
    {
        return $this->desiredDelta;
    }

    /**
     * @return ActionResult[]
     */
    public function getActionExecutions(): array
    {
        return $this->actions;
    }

    public function addActionResult(ActionResult $actionResult): void
    {
        $this->actions[] = $actionResult;
    }

    /**
     * @return GateEvaluation[]
     */
    public function getGateEvaluations(): array
    {
        return $this->gateEvaluations;
    }

    public function addGateEvaluation(Gate $gate, GateResult $result, bool $isActionGate): void
    {
        $this->gateEvaluations[] = new GateEvaluation($gate, $result, $isActionGate);
    }

    public function didGatesPass(): bool
    {
        $availableGates = count($this->getConfiguration()->getTransitionGates());
        if ($availableGates === 0) {
            return true;
        }

        if ($availableGates !== count($this->getGateEvaluations())) {
            return false;
        }
        foreach ($this->getGateEvaluations() as $gateEvaluation) {
            if ($gateEvaluation->result !== GateResult::ALLOW) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return ActionSkip[]
     */
    public function getActionSkips(): array
    {
        return $this->actionSkips;
    }

    public function addActionSkip(Action $action, GateResult $gateResult): void
    {
        $this->actionSkips[] = new ActionSkip($action, $gateResult);
    }

    public function markAsCompleted(): void
    {
        $this->status = ExecutionState::CONTINUE;
    }

    public function markAsPaused(): void
    {
        $this->status = ExecutionState::PAUSE;
    }

    public function markAsStopped(): void
    {
        $this->status = ExecutionState::STOP;
    }

    public function clearPauseStatus(): void
    {
        if ($this->status === ExecutionState::PAUSE) {
            $this->status = null;
        }
    }

    public function isPaused(): bool
    {
        return $this->status === ExecutionState::PAUSE;
    }

    public function isCompleted(): bool
    {
        return $this->status === ExecutionState::CONTINUE;
    }

    public function isStopped(): bool
    {
        return $this->status === ExecutionState::STOP;
    }

    public function getLockState(): LockState
    {
        return $this->lockState;
    }

    public function setLockState(LockState $lockState): void
    {
        $this->lockState = $lockState;
    }

    public function markAsSkippedDueToLock(): void
    {
        $this->skippedDueToLock = true;
    }

    public function wasSkippedDueToLock(): bool
    {
        return $this->skippedDueToLock;
    }

    /**
     * Get metadata from the last PAUSE or STOP action.
     *
     * Returns null if no PAUSE/STOP action has been executed or if the action had no metadata.
     */
    public function getStatusMetadata(): mixed
    {
        // Find the last action that caused PAUSE or STOP
        foreach (array_reverse($this->actions) as $actionResult) {
            if ($actionResult->executionState === ExecutionState::PAUSE
                || $actionResult->executionState === ExecutionState::STOP
            ) {
                return $actionResult->metadata;
            }
        }

        return null;
    }

    /**
     * Serialize the context to a JSON string for persistence.
     */
    public function serialize(): string
    {
        $data = [
            'initialState' => $this->initialState->toArray(),
            'currentState' => $this->currentState->toArray(),
            'desiredDelta' => $this->desiredDelta->asArray(),
            'status' => $this->status?->name,
            'skippedDueToLock' => $this->skippedDueToLock,
            'lockState' => $this->lockState->toArray(),
            'configuration' => [
                'transitionGates' => array_map(
                    fn ($gate) => get_class($gate),
                    $this->configuration->getTransitionGates()
                ),
                'actions' => array_map(
                    fn ($action) => get_class($action),
                    $this->configuration->getActions()
                ),
            ],
            'gateEvaluations' => array_map(
                fn (GateEvaluation $eval) => [
                    'gate' => get_class($eval->gate),
                    'result' => $eval->result->name,
                    'isActionGate' => $eval->isActionGate,
                ],
                $this->gateEvaluations
            ),
            'actionExecutions' => array_map(
                fn (ActionResult $result) => [
                    'executionState' => $result->executionState->name,
                    'newState' => $result->newState?->toArray(),
                    'metadata' => $result->metadata,
                ],
                $this->actions
            ),
            'actionSkips' => array_map(
                fn (ActionSkip $skip) => [
                    'action' => get_class($skip->action),
                    'gateResult' => $skip->gateResult->name,
                ],
                $this->actionSkips
            ),
        ];

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /**
     * Deserialize a context from a JSON string.
     *
     * @param string $data
     * @param StateFactory $stateFactory
     * @param ActionFactory $actionFactory
     * @param GateFactory $gateFactory
     * @throws JsonException
     * @return self
     */
    public static function unserialize(
        string $data,
        StateFactory $stateFactory,
        ActionFactory $actionFactory,
        GateFactory $gateFactory
    ): self {
        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new JsonException('Invalid JSON data: expected object');
        }

        // Reconstruct states
        $initialState = $stateFactory->fromArray($decoded['initialState'] ?? []);
        $currentState = $stateFactory->fromArray($decoded['currentState'] ?? []);

        // Reconstruct configuration
        $configuration = self::restoreConfiguration($decoded, $actionFactory, $gateFactory);

        // Create new context
        /** @var array<string, mixed> $deltaData */
        $deltaData = $decoded['desiredDelta'] ?? [];
        $desiredDelta = new ArrayDelta($deltaData);
        $context = new self($initialState, $desiredDelta, $configuration);

        // Restore current state
        $context->currentState = $currentState;

        // Restore status
        self::restoreStatus($context, $decoded);

        // Restore lock state
        $lockStateData = $decoded['lockState'] ?? [];
        $context->lockState = is_array($lockStateData) ? LockState::fromArray($lockStateData) : new LockState();
        $context->skippedDueToLock = $decoded['skippedDueToLock'] ?? false;

        // Restore execution data
        self::restoreGateEvaluations($context, $decoded, $gateFactory);
        self::restoreActionExecutions($context, $decoded, $stateFactory);
        self::restoreActionSkips($context, $decoded, $actionFactory);

        return $context;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function restoreConfiguration(
        array $decoded,
        ActionFactory $actionFactory,
        GateFactory $gateFactory
    ): Configuration {
        $configData = $decoded['configuration'] ?? [];

        /** @var array<int, string> $gateClassNames */
        $gateClassNames = is_array($configData) ? ($configData['transitionGates'] ?? []) : [];
        $transitionGates = array_map(
            fn (string $className) => $gateFactory->fromClassName($className),
            $gateClassNames
        );

        /** @var array<int, string> $actionClassNames */
        $actionClassNames = is_array($configData) ? ($configData['actions'] ?? []) : [];
        $actions = array_map(
            fn (string $className) => $actionFactory->fromClassName($className),
            $actionClassNames
        );

        return new Configuration($transitionGates, $actions);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function restoreStatus(self $context, array $decoded): void
    {
        if (isset($decoded['status']) && is_string($decoded['status'])) {
            $context->status = match ($decoded['status']) {
                'CONTINUE' => ExecutionState::CONTINUE,
                'PAUSE' => ExecutionState::PAUSE,
                'STOP' => ExecutionState::STOP,
                default => null,
            };
        }
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function restoreGateEvaluations(self $context, array $decoded, GateFactory $gateFactory): void
    {
        /** @var array<int, array{gate: string, result: string, isActionGate: bool}> $gateEvaluations */
        $gateEvaluations = $decoded['gateEvaluations'] ?? [];
        foreach ($gateEvaluations as $evalData) {
            $gate = $gateFactory->fromClassName($evalData['gate']);
            $result = match ($evalData['result']) {
                'ALLOW' => GateResult::ALLOW,
                'DENY' => GateResult::DENY,
                'SKIP_IDEMPOTENT' => GateResult::SKIP_IDEMPOTENT,
                default => GateResult::ALLOW,
            };
            $context->gateEvaluations[] = new GateEvaluation($gate, $result, $evalData['isActionGate']);
        }
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function restoreActionExecutions(self $context, array $decoded, StateFactory $stateFactory): void
    {
        /** @var array<int, array{executionState: string, newState: array<string, mixed>|null, metadata: mixed}> $actionExecutions */
        $actionExecutions = $decoded['actionExecutions'] ?? [];
        foreach ($actionExecutions as $actionData) {
            $executionState = match ($actionData['executionState']) {
                'CONTINUE' => ExecutionState::CONTINUE,
                'PAUSE' => ExecutionState::PAUSE,
                'STOP' => ExecutionState::STOP,
                default => ExecutionState::CONTINUE,
            };
            $newState = $actionData['newState'] !== null
                ? $stateFactory->fromArray($actionData['newState'])
                : null;
            $context->actions[] = new ActionResult($executionState, $newState, $actionData['metadata']);
        }
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function restoreActionSkips(self $context, array $decoded, ActionFactory $actionFactory): void
    {
        /** @var array<int, array{action: string, gateResult: string}> $actionSkips */
        $actionSkips = $decoded['actionSkips'] ?? [];
        foreach ($actionSkips as $skipData) {
            $action = $actionFactory->fromClassName($skipData['action']);
            $gateResult = match ($skipData['gateResult']) {
                'ALLOW' => GateResult::ALLOW,
                'DENY' => GateResult::DENY,
                'SKIP_IDEMPOTENT' => GateResult::SKIP_IDEMPOTENT,
                default => GateResult::ALLOW,
            };
            $context->actionSkips[] = new ActionSkip($action, $gateResult);
        }
    }
}
