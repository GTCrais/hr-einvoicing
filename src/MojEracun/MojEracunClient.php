<?php

namespace GTCrais\HrEInvoicing\MojEracun;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use GTCrais\HrEInvoicing\Concerns\ChoosesEnvironment;
use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceUnavailableException;
use GTCrais\HrEInvoicing\FiscalizationStatus;
use GTCrais\HrEInvoicing\MojEracun\Contracts\AccountProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The line to Moj-eRačun's REST API, as its demo service answered on 2026-10-22: every call carries the account the
 * AccountProvider gives (the username and password, the issuer's OIB as the company, the business unit when there is
 * one and the software identifier), the document goes as the XML text of a JSON request, and the answers come back
 * as JSON whose keys are written now with a capital and now without, so they are read either way. A query answers
 * with a list; the tax authority's answers about an e-invoice come as a list of documents, each with its messages,
 * and as a 404 while there are none yet. Moj-eRačun's status codes (20 taken in, 30 sent, 40 delivered, 50 refused,
 * 70 reported to the tax authority because the buyer takes no e-invoices) become the package's DocumentStatus. The
 * environment is "demo" or "production", the account's own when it names one, and a call may take attemptSeconds;
 * the service provider builds it from config('hrEInvoicing').
 */
class MojEracunClient implements EInvoiceGateway
{
	use ChoosesEnvironment;

	/** The intermediary's services by environment */
	public const array SERVICES = [
		'demo' => 'https://demo.moj-eracun.hr',
		'production' => 'https://www.moj-eracun.hr',
	];

	/** The tax authority's answers about an e-invoice it was given: through the e-invoice exchange, or as an eReport */
	protected const array FISCALIZATION_MESSAGE_TYPES = [0, 4];

	public function __construct(
		protected AccountProvider $accounts,
		protected string $environment = 'demo',
		protected int $attemptSeconds = 15
	) {}

	public function ready(): bool
	{
		return $this->accounts->account() !== null;
	}

	public function send(string $xml): array
	{
		$document = $this->post('/apis/v2/send', ['file' => $xml]);
		$electronicId = $this->value($document, 'ElectronicId');

		if (blank($electronicId)) {
			throw new RuntimeException('The intermediary answered without a document identifier.');
		}

		return ['electronicId' => (string) $electronicId, 'status' => $this->documentStatus($this->value($document, 'StatusId'))];
	}

	/**
	 * Looked up in the outbox from the day before the first try on, by the number the document carries.
	 */
	public function sentDocument(string $number, DateTimeInterface $since): ?array
	{
		$documents = $this->post('/apis/v2/queryOutbox', [
			'from' => CarbonImmutable::instance($since)->subDay()->toDateString(),
			'to' => CarbonImmutable::now()->addDay()->toDateString(),
		], quietlyMissing: true);

		$document = collect(array_is_list($documents) ? $documents : [$documents])
			->filter(fn ($row) => is_array($row) && (string) $this->value($row, 'DocumentNr') === $number && filled($this->value($row, 'ElectronicId')))
			->sortByDesc(fn (array $row) => (int) $this->value($row, 'ElectronicId'))
			->first();

		return $document === null ? null : ['electronicId' => (string) $this->value($document, 'ElectronicId'), 'status' => $this->documentStatus($this->value($document, 'StatusId'))];
	}

	public function status(string $electronicId): array
	{
		$documents = $this->post('/apis/v2/queryOutbox', ['electronicId' => (int) $electronicId]);
		$document = collect(array_is_list($documents) ? $documents : [$documents])
			->first(fn ($row) => is_array($row) && (string) $this->value($row, 'ElectronicId') === $electronicId);

		if ($document === null) {
			throw new RuntimeException('The intermediary does not know the document ' . $electronicId . '.');
		}

		$status = $this->documentStatus($this->value($document, 'StatusId'));
		[$fiscalization, $error] = $this->fiscalization($electronicId);

		if ($status === DocumentStatus::REFUSED && $error === null) {
			$error = trim((string) ($this->value($document, 'StatusName') ?? '')) ?: null;
		}

		return ['status' => $status, 'fiscalization' => $fiscalization, 'error' => $error];
	}

	public function reportPayment(string $electronicId, string $paidOn, float $amount): void
	{
		$this->post('/api/fiscalization/markPaid', [
			'electronicId' => (int) $electronicId,
			'paymentDate' => $paidOn,
			'paymentAmount' => round($amount, 2),
			'paymentMethod' => 'T',
		]);
	}

	public function ping(): void
	{
		$account = $this->accounts->account() ?? throw new RuntimeException('The intermediary account is not set up.');

		// The service answers its ping without the account; while it does not, the account cannot be checked
		try {
			$response = Http::acceptJson()->timeout($this->seconds())->get($this->url('/apis/v2/Ping/', $account));
		} catch (ConnectionException $exception) {
			throw new EInvoiceUnavailableException($exception->getMessage());
		}

		if (!$response->successful()) {
			throw new EInvoiceUnavailableException('HTTP ' . $response->status());
		}

		// The account itself is proven by a query that needs it
		$this->post('/apis/v2/queryOutbox', ['from' => CarbonImmutable::now()->subDay()->toDateString(), 'to' => CarbonImmutable::now()->toDateString()]);
	}

	/**
	 * Moj-eRačun's status code of a document as the package's standing.
	 */
	protected function documentStatus(mixed $statusId): DocumentStatus
	{
		return match ((int) ($statusId ?? 20)) {
			50 => DocumentStatus::REFUSED,
			70 => DocumentStatus::REPORTED,
			40 => DocumentStatus::DELIVERED,
			default => DocumentStatus::SENT,
		};
	}

	/**
	 * The tax authority's latest answer about the e-invoice, or nothing while it has given none: taken, refused with
	 * its code and reason, or waiting.
	 *
	 * @return array{0: ?FiscalizationStatus, 1: ?string}
	 */
	protected function fiscalization(string $electronicId): array
	{
		try {
			$documents = $this->post('/api/fiscalization/statusOutbox', ['electronicId' => (int) $electronicId], quietlyMissing: true);
		} catch (RuntimeException) {
			// The answer may not be there yet, or the service may be down; the document's own status stands either way
			return [null, null];
		}

		$latest = collect(array_is_list($documents) ? $documents : [$documents])
			->filter(fn ($row) => is_array($row) && (string) $this->value($row, 'electronicId') === $electronicId)
			->flatMap(fn (array $row) => (array) ($this->value($row, 'messages') ?? []))
			->filter(fn ($message) => is_array($message) && in_array((int) $this->value($message, 'messageType'), self::FISCALIZATION_MESSAGE_TYPES, true))
			->sortBy(fn (array $message) => (string) $this->value($message, 'dateOfFiscalization'))
			->last();

		if ($latest === null || $this->value($latest, 'status') === null) {
			return [null, null];
		}

		return match ((int) $this->value($latest, 'status')) {
			0 => [FiscalizationStatus::FISCALIZED, null],
			1 => [FiscalizationStatus::FAILED, $this->messageError($latest)],
			default => [FiscalizationStatus::WAITING, null],
		};
	}

	/**
	 * The reason the tax authority gave for refusing, with its code.
	 *
	 * @param  array<string, mixed>  $message
	 */
	protected function messageError(array $message): string
	{
		$code = trim((string) $this->value($message, 'errorCode'));
		$description = trim((string) ($this->value($message, 'errorCodeDescription') ?: $this->value($message, 'message')));

		return trim($code . ' ' . $description) ?: 'The tax authority refused the e-invoice.';
	}

	/**
	 * A JSON call with the account; the intermediary's refusal becomes an exception with its message. A 404 is no
	 * error for the calls that answer it while they have nothing to tell, and comes back empty.
	 *
	 * @param  array<string, mixed>  $payload
	 * @return array<int|string, mixed>
	 */
	protected function post(string $path, array $payload, bool $quietlyMissing = false): array
	{
		$account = $this->accounts->account() ?? throw new RuntimeException('The intermediary account is not set up.');
		$url = $this->url($path, $account);

		// The service's environment is the account's affair, not the service's
		unset($account['environment']);

		try {
			$response = Http::acceptJson()
				->timeout($this->seconds())
				->connectTimeout(5)
				->post($url, [...$account, ...$payload]);
		} catch (ConnectionException $exception) {
			throw new RuntimeException($exception->getMessage());
		}

		if ($quietlyMissing && $response->status() === 404) {
			return [];
		}

		// A document it could not take is refused for good; anything else may pass on a later try
		if (in_array($response->status(), [400, 422], true)) {
			throw new EInvoiceRefusedException($this->failure($response));
		}

		if (!$response->successful()) {
			throw new RuntimeException($this->failure($response));
		}

		$json = $response->json();

		return is_array($json) ? $json : [];
	}

	/**
	 * A key of an answer, written with a capital first letter or without.
	 *
	 * @param  array<int|string, mixed>  $row
	 */
	protected function value(array $row, string $key): mixed
	{
		if (array_key_exists($key, $row)) {
			return $row[$key];
		}

		foreach ($row as $name => $value) {
			if (is_string($name) && strcasecmp($name, $key) === 0) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Why the intermediary refused: the rules the document broke, the tax authority's code and reason, or the
	 * message, else the start of the body.
	 */
	protected function failure(Response $response): string
	{
		$json = $response->json();
		$prefix = 'HTTP ' . $response->status() . ': ';

		if (is_array($json)) {
			$file = $this->value($json, 'File');
			$rules = is_array($file) ? (array) ($this->value($file, 'Messages') ?? []) : [];

			if ($rules !== []) {
				return $prefix . implode(' ', array_map(strval(...), $rules));
			}

			$message = $this->value($json, 'message') ?? $this->value($json, 'error') ?? $this->value($json, 'title');
			$message = is_string($message) || $message === null ? $message : json_encode($message);
			$specifics = $this->value($json, 'taxAdministrationSpecifics');

			if (is_array($specifics) && filled($this->value($specifics, 'code'))) {
				return $prefix . trim($this->value($specifics, 'code') . ' ' . $this->value($specifics, 'description') . (filled($message) ? ' (' . $message . ')' : ''));
			}

			if (filled($message)) {
				return $prefix . $message;
			}
		}

		return 'HTTP ' . $response->status() . ($response->body() !== '' ? ': ' . mb_substr(trim($response->body()), 0, 300) : '');
	}

	/**
	 * @param  array<string, mixed>  $account
	 */
	protected function url(string $path, array $account): string
	{
		return $this->serviceFor(self::SERVICES, $account) . $path;
	}

	protected function seconds(): int
	{
		return max(1, $this->attemptSeconds);
	}
}
