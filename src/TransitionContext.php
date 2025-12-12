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

    private ExecutionStatus $executionStatus;

    private LockState $lockState;

    public function __construct(
        private readonly State $initialState,
        private readonly Delta $desiredDelta,
        private readonly Configuration $configuration,
    ) {
        $this->history = new ExecutionHistory($initialState);
        $this->executionStatus = new ExecutionStatus();
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
        $this->executionStatus->markCompleted();
    }

    public function markAsPaused(mixed $metadata = null): void
    {
        $this->executionStatus->markPaused($metadata);
    }

    public function markAsStopped(mixed $metadata = null): void
    {
        $this->executionStatus->markStopped($metadata);
    }

    public function clearPauseStatus(): void
    {
        $this->executionStatus->clearPauseStatus();
    }

    public function isPaused(): bool
    {
        return $this->executionStatus->isPaused();
    }

    public function isCompleted(): bool
    {
        return $this->executionStatus->isCompleted();
    }

    public function isStopped(): bool
    {
        return $this->executionStatus->isStopped();
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
        $this->executionStatus->markSkippedDueToLock();
    }

    public function wasSkippedDueToLock(): bool
    {
        return $this->executionStatus->wasSkippedDueToLock();
    }

    /**
     * Get metadata from the current execution status.
     *
     * Returns the metadata provided when marking as paused or stopped.
     * If no metadata was provided to ExecutionStatus, falls back to history.
     */
    public function getStatusMetadata(): mixed
    {
        $statusMetadata = $this->executionStatus->getMetadata();
        if ($statusMetadata !== null) {
            return $statusMetadata;
        }

        // Fallback to history for backward compatibility
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
            'executionStatus' => [
                'completed' => $this->executionStatus->isCompleted(),
                'paused' => $this->executionStatus->isPaused(),
                'stopped' => $this->executionStatus->isStopped(),
                'skippedDueToLock' => $this->executionStatus->wasSkippedDueToLock(),
                'metadata' => $this->executionStatus->getMetadata(),
            ],
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
                    'timestamp' => $eval->timestamp,
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
                    'timestamp' => $skip->timestamp,
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

        // Restore execution status
        self::restoreExecutionStatus($context, $decoded);

        // Restore lock state
        $lockStateData = $decoded['lockState'] ?? [];
        $context->lockState = is_array($lockStateData) ? LockState::fromArray($lockStateData) : new LockState();

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
    private static function restoreExecutionStatus(self $context, array $decoded): void
    {
        if (isset($decoded['executionStatus']) && is_array($decoded['executionStatus'])) {
            self::restoreNewFormatExecutionStatus($context, $decoded['executionStatus']);

            return;
        }

        if (isset($decoded['status']) && is_string($decoded['status'])) {
            self::restoreLegacyFormatExecutionStatus($context, $decoded);
        }
    }

    /**
     * @param array<string, mixed> $statusData
     */
    private static function restoreNewFormatExecutionStatus(self $context, array $statusData): void
    {
        $metadata = $statusData['metadata'] ?? null;

        if ($statusData['completed'] ?? false) {
            $context->executionStatus->markCompleted();
        } elseif ($statusData['paused'] ?? false) {
            $context->executionStatus->markPaused($metadata);
        } elseif ($statusData['stopped'] ?? false) {
            $context->executionStatus->markStopped($metadata);
        }

        if ($statusData['skippedDueToLock'] ?? false) {
            $context->executionStatus->markSkippedDueToLock();
        }
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function restoreLegacyFormatExecutionStatus(self $context, array $decoded): void
    {
        match ($decoded['status']) {
            'CONTINUE' => $context->executionStatus->markCompleted(),
            'PAUSE' => $context->executionStatus->markPaused(),
            'STOP' => $context->executionStatus->markStopped(),
            default => null,
        };

        if ($decoded['skippedDueToLock'] ?? false) {
            $context->executionStatus->markSkippedDueToLock();
        }
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function restoreGateEvaluationsCollection(array $decoded, GateFactory $gateFactory): GateEvaluationCollection
    {
        /** @var array<int, array{gate: string, result: string, isActionGate: bool, timestamp?: float}> $gateEvaluationsData */
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
            $timestamp = $evalData['timestamp'] ?? 0.0;
            $evaluations[] = new GateEvaluation($gate, $result, $evalData['isActionGate'], $timestamp);
        }

        return GateEvaluationCollection::fromArray($evaluations);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private static function restoreActionExecutionsCollection(array $decoded, StateFactory $stateFactory): ActionResultCollection
    {
        /** @var array<int, array{executionState: string, newState: array<string, mixed>|null, metadata: mixed}> $actionExecutionsData */
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
        /** @var array<int, array{action: string, gateResult: string, timestamp?: float}> $actionSkipsData */
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
            $timestamp = $skipData['timestamp'] ?? 0.0;
            $skips[] = new ActionSkip($action, $gateResult, $timestamp);
        }

        return ActionSkipCollection::fromArray($skips);
    }
}
