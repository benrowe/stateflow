<?php

declare(strict_types=1);

namespace BenRowe\StateFlow;

use BenRowe\StateFlow\Action\Action;
use BenRowe\StateFlow\Action\ActionResult;
use BenRowe\StateFlow\Action\ActionResultCollection;
use BenRowe\StateFlow\Action\ExecutionState;
use BenRowe\StateFlow\Configuration\Configuration;
use BenRowe\StateFlow\Gate\Gate;
use BenRowe\StateFlow\Gate\GateResult;
use BenRowe\StateFlow\Locking\LockState;
use JsonException;

class TransitionContext
{
    private ExecutionHistory $history;

    private ?ExecutionState $status = null;

    private LockState $lockState;

    private bool $skippedDueToLock = false;

    public function __construct(
        private readonly State $initialState,
        private readonly Delta $desiredDelta,
        private readonly Configuration $configuration,
    ) {
        $this->history = new ExecutionHistory($initialState);
        $this->lockState = new LockState();
    }

    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    public function getCurrentState(): State
    {
        return $this->history->getCurrentState();
    }

    public function getInitialState(): State
    {
        return $this->initialState;
    }

    public function updateCurrentState(State $newState): void
    {
        $this->history = $this->history->updateCurrentState($newState);
    }

    public function getDesiredDelta(): Delta
    {
        return $this->desiredDelta;
    }

    public function getActionExecutions(): ActionResultCollection
    {
        return $this->history->getActionExecutions();
    }

    public function addActionResult(ActionResult $actionResult): void
    {
        $this->history = $this->history->recordActionExecution($actionResult);
    }

    public function getGateEvaluations(): GateEvaluationCollection
    {
        return $this->history->getGateEvaluations();
    }

    public function addGateEvaluation(Gate $gate, GateResult $result, bool $isActionGate): void
    {
        $this->history = $this->history->recordGateEvaluation($gate, $result, $isActionGate);
    }

    public function didGatesPass(): bool
    {
        $availableGates = $this->getConfiguration()->transitionGates->count();
        if ($availableGates === 0) {
            return true;
        }

        if ($availableGates !== count($this->getGateEvaluations())) {
            return false;
        }
        foreach ($this->getGateEvaluations()->toArray() as $gateEvaluation) {
            if ($gateEvaluation->result !== GateResult::ALLOW) {
                return false;
            }
        }

        return true;
    }

    public function getActionSkips(): ActionSkipCollection
    {
        return $this->history->getActionSkips();
    }

    public function addActionSkip(Action $action, GateResult $gateResult): void
    {
        $this->history = $this->history->recordActionSkip($action, $gateResult);
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
        return $this->history->getStatusMetadata();
    }

    /**
     * Serialize the context to a JSON string for persistence.
     */
    public function serialize(): string
    {
        $data = [
            'initialState' => $this->initialState->toArray(),
            'currentState' => $this->history->getCurrentState()->toArray(),
            'desiredDelta' => $this->desiredDelta->asArray(),
            'status' => $this->status?->name,
            'skippedDueToLock' => $this->skippedDueToLock,
            'lockState' => $this->lockState->toArray(),
            'configuration' => [
                'transitionGates' => array_map(
                    fn ($gate) => get_class($gate),
                    $this->configuration->transitionGates->toArray()
                ),
                'actions' => array_map(
                    fn ($action) => get_class($action),
                    $this->configuration->actions->toArray()
                ),
            ],
            'gateEvaluations' => array_map(
                fn (GateEvaluation $eval) => [
                    'gate' => get_class($eval->gate),
                    'result' => $eval->result->name,
                    'isActionGate' => $eval->isActionGate,
                ],
                $this->history->getGateEvaluations()->toArray()
            ),
            'actionExecutions' => array_map(
                fn (ActionResult $result) => [
                    'executionState' => $result->executionState->name,
                    'newState' => $result->newState?->toArray(),
                    'metadata' => $result->metadata,
                ],
                $this->history->getActionExecutions()->toArray()
            ),
            'actionSkips' => array_map(
                fn (ActionSkip $skip) => [
                    'action' => get_class($skip->action),
                    'gateResult' => $skip->gateResult->name,
                ],
                $this->history->getActionSkips()->toArray()
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

        // Restore execution history
        $gateEvaluations = self::restoreGateEvaluationsCollection($decoded, $gateFactory);
        $actionExecutions = self::restoreActionExecutionsCollection($decoded, $stateFactory);
        $actionSkips = self::restoreActionSkipsCollection($decoded, $actionFactory);

        $context->history = new ExecutionHistory(
            $initialState,
            $gateEvaluations,
            $actionExecutions,
            $actionSkips,
            $currentState
        );

        // Restore status
        self::restoreStatus($context, $decoded);

        // Restore lock state
        $lockStateData = $decoded['lockState'] ?? [];
        $context->lockState = is_array($lockStateData) ? LockState::fromArray($lockStateData) : new LockState();
        $context->skippedDueToLock = $decoded['skippedDueToLock'] ?? false;

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
    private static function restoreGateEvaluationsCollection(array $decoded, GateFactory $gateFactory): GateEvaluationCollection
    {
        /** @var array<int, array{gate: string, result: string, isActionGate: bool}> $gateEvaluations */
        $gateEvaluationsData = $decoded['gateEvaluations'] ?? [];
        $evaluations = [];

        foreach ($gateEvaluationsData as $evalData) {
            $gate = $gateFactory->fromClassName($evalData['gate']);
            $result = match ($evalData['result']) {
                'ALLOW' => GateResult::ALLOW,
                'DENY' => GateResult::DENY,
                'SKIP_IDEMPOTENT' => GateResult::SKIP_IDEMPOTENT,
                default => GateResult::ALLOW,
            };
            $evaluations[] = new GateEvaluation($gate, $result, $evalData['isActionGate']);
        }

        return GateEvaluationCollection::fromArray($evaluations);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function restoreActionExecutionsCollection(array $decoded, StateFactory $stateFactory): ActionResultCollection
    {
        /** @var array<int, array{executionState: string, newState: array<string, mixed>|null, metadata: mixed}> $actionExecutions */
        $actionExecutionsData = $decoded['actionExecutions'] ?? [];
        $results = [];

        foreach ($actionExecutionsData as $actionData) {
            $executionState = match ($actionData['executionState']) {
                'CONTINUE' => ExecutionState::CONTINUE,
                'PAUSE' => ExecutionState::PAUSE,
                'STOP' => ExecutionState::STOP,
                default => ExecutionState::CONTINUE,
            };
            $newState = $actionData['newState'] !== null
                ? $stateFactory->fromArray($actionData['newState'])
                : null;
            $results[] = new ActionResult($executionState, $newState, $actionData['metadata']);
        }

        return ActionResultCollection::fromArray($results);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function restoreActionSkipsCollection(array $decoded, ActionFactory $actionFactory): ActionSkipCollection
    {
        /** @var array<int, array{action: string, gateResult: string}> $actionSkips */
        $actionSkipsData = $decoded['actionSkips'] ?? [];
        $skips = [];

        foreach ($actionSkipsData as $skipData) {
            $action = $actionFactory->fromClassName($skipData['action']);
            $gateResult = match ($skipData['gateResult']) {
                'ALLOW' => GateResult::ALLOW,
                'DENY' => GateResult::DENY,
                'SKIP_IDEMPOTENT' => GateResult::SKIP_IDEMPOTENT,
                default => GateResult::ALLOW,
            };
            $skips[] = new ActionSkip($action, $gateResult);
        }

        return ActionSkipCollection::fromArray($skips);
    }
}
