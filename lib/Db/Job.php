<?php

namespace OCA\OpenConnector\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use RuntimeException;

/**
 * Class Job
 *
 * Represents a scheduled job configuration entity that defines automated tasks to be executed.
 *
 * @package OCA\OpenConnector\Db
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class Job extends Entity implements JsonSerializable
{
    protected ?string $uuid = null;
	protected ?string $name = null;
	protected ?string $description = null;
	protected ?string $reference = null; // The reference of the Job
	protected ?string $version = '0.0.0'; // The version of the Job
	protected ?string $jobClass = 'OCA\OpenConnector\Action\PingAction';
	protected ?array $arguments = null;
	protected ?int $interval = 3600; // seconds in an hour
	protected ?int $executionTime = 3600; // maximum execution time in seconds
	protected ?bool $timeSensitive = true; // if the job is time sensitive and should be executed even if the server is under heavy load
	protected ?bool $allowParallelRuns = false; // if the job can be executed in parallel
	protected ?bool $isEnabled = true; // if the job is enabled
	protected ?bool $singleRun = false; // if set, the job will only run once and then disable itself
	protected ?DateTime $scheduleAfter = null; // if the job should be executed after a certain date and time
	protected ?string $userId = null; // the user which the job is running for security reasons
	protected ?string $jobListId = null; // the id of the job in the job list
	protected ?int $logRetention = 3600; // seconds to save all logs
	protected ?int $errorRetention = 86400; // seconds to save error logs
	protected ?DateTime $lastRun = null; // the last time the job was run
	protected ?DateTime $nextRun = null; // the next time the job will be run
	protected ?DateTime $created = null; // the date and time the job was created
	protected ?DateTime $updated = null; // the date and time the job was updated
	protected ?array $configurations = []; // Array of configuration IDs that this job belongs to
	protected ?string $status = null;
	protected ?string $slug = null;

	/**
	 * Get the job arguments
	 *
	 * @return array The job arguments or empty array if null
	 */
	public function getArguments(): array
	{
		return $this->arguments ?? [];
	}

	public function __construct() {
        $this->addType('uuid', 'string');
		$this->addType('name', 'string');
		$this->addType('description', 'string');
		$this->addType(fieldName:'reference', type: 'string');
		$this->addType('version', 'string');
		$this->addType('jobClass', 'string');
		$this->addType('arguments', 'json');
		$this->addType('interval', 'integer');
		$this->addType('executionTime', 'integer');
		$this->addType('timeSensitive', 'boolean');
		$this->addType('allowParallelRuns', 'boolean');
		$this->addType('isEnabled', 'boolean');
		$this->addType('singleRun', 'boolean');
		$this->addType('scheduleAfter', 'datetime');
		$this->addType('userId', 'string');
		$this->addType('jobListId', 'string');
		$this->addType('logRetention', 'integer');
		$this->addType('errorRetention', 'integer');
		$this->addType('lastRun', 'datetime');
		$this->addType('nextRun', 'datetime');
		$this->addType('created', 'datetime');
		$this->addType('updated', 'datetime');
		$this->addType('configurations', 'json');
		$this->addType('status', 'string');
		$this->addType('slug', 'string');
	}

	public function getJsonFields(): array
	{
		return array_keys(
			array_filter($this->getFieldTypes(), function ($field) {
				return $field === 'json';
			})
		);
	}


	/**
	 * Get the slug for the endpoint.
	 * If the slug is not set, generate one from the name.
	 *
	 * @return string The slug for the endpoint
	 * @phpstan-return non-empty-string
	 * @psalm-return non-empty-string
	 */
	public function getSlug(): string
	{
		// Check if the slug is already set
		if (!empty($this->slug)) {
			return $this->slug;
		}

		// Generate a slug from the name if not set
		// Convert the name to lowercase, replace spaces with hyphens, and remove non-alphanumeric characters
		$generatedSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($this->name)));

		// Ensure the generated slug is not empty
		if (empty($generatedSlug)) {
			throw new RuntimeException('Unable to generate a valid slug from the name.');
		}

		return $generatedSlug;
	}

	public function hydrate(array $object): self
	{
		$jsonFields = $this->getJsonFields();

		foreach ($object as $key => $value) {
			if (in_array($key, $jsonFields) === true && $value === []) {
				$value = [];
			}

			$method = 'set'.ucfirst($key);

			try {
				$this->$method($value);
			} catch (\Exception $exception) {
//				("Error writing $key");
			}
		}

		return $this;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'name' => $this->name,
			'description' => $this->description,
			'reference' => $this->reference,
			'version' => $this->version,
			'jobClass' => $this->jobClass,
			'arguments' => $this->arguments,
			'interval' => $this->interval,
			'executionTime' => $this->executionTime,
			'timeSensitive' => $this->timeSensitive,
			'allowParallelRuns' => $this->allowParallelRuns,
			'isEnabled' => $this->isEnabled,
			'singleRun' => $this->singleRun,
			'scheduleAfter' => isset($this->scheduleAfter) ? $this->scheduleAfter->format('c') : $this->scheduleAfter,
			'userId' => $this->userId,
			'jobListId' => $this->jobListId,
			'logRetention' => $this->logRetention,
			'errorRetention' => $this->errorRetention,
			'lastRun' => isset($this->lastRun) ? $this->lastRun->format('c') : null,
            'nextRun' => isset($this->nextRun) ? $this->nextRun->format('c') : null,
            'created' => isset($this->created) ? $this->created->format('c') : null,
			'updated' => isset($this->updated) ? $this->updated->format('c') : null,
			'configurations' => $this->configurations,
			'status' => $this->status,
			'slug' => $this->slug,
		];
	}
}
