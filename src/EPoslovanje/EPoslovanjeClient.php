<?php

namespace GTCrais\HrEInvoicing\EPoslovanje;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use GTCrais\HrEInvoicing\Concerns\ChoosesEnvironment;
use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\EPoslovanje\Contracts\AccountProvider;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceUnavailableException;
use GTCrais\HrEInvoicing\FiscalizationStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The line to ePoslovanje's REST API v2 (Pondi d.o.o., doc.eposlovanje.hr, 2.0.21 of 24.09.2026): every call carries
 * the account's API key in the Authorization header; the document goes as the XML text of a JSON request with the
 * software identifier, and ePoslovanje validates it, signs it, reports it to the tax authority when the account has
 * outgoing fiscalization switched on, and queues it for the buyer's intermediary. A document's answer names its
 * standing with one of ePoslovanje's codes (1 waiting, 2 being sent, 3 sent, 4 delivered, 5 approved, 6 rejected by
 * the buyer, 7 paid, 8 partly paid, 9 delivery failed) and lists its eReporting requests, of which the first kind is
 * the document's own fiscalization. The environment is "demo" (the test server) or "production", and a call may
 * take attemptSeconds; the service provider builds it from config('hrEInvoicing').
 */
class EPoslovanjeClient implements EInvoiceGateway
{
	use ChoosesEnvironment;

	/** The intermediary's services by environment */
	public const array SERVICES = [
		'demo' => 'https://test.eposlovanje.hr',
		'production' => 'https://eracun.eposlovanje.hr',
	];

	/** The eReporting request that is the document's own fiscalization ("Evidencija izlaznog računa") */
	protected const int FISCALIZATION_REQUEST = 1;

	/** The payment kind of a bank transfer ("T") in a payment report */
	protected const int PAYMENT_BY_TRANSFER = 1;

	/** ePoslovanje's codes of a document the buyer has */
	protected const array DELIVERED_CODES = [4, 5, 7, 8];

	/** ePoslovanje's codes of a document that will not reach the buyer */
	protected const array REJECTED_CODES = [6, 9];

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
		$account = $this->account();
		$answer = $this->call('post', '/api/v2/document/send', ['document' => $xml, 'softwareId' => $account['softwareId']], $account, refusable: true);

		if (blank($answer['id'] ?? null)) {
			throw new RuntimeException('The intermediary answered without a document identifier.');
		}

		return ['electronicId' => (string) $answer['id'], 'status' => DocumentStatus::SENT];
	}

	/**
	 * Looked up among the outgoing documents taken in from the day before the first try on, by the number the
	 * document carries; the newest of that number when it was sent more than once.
	 */
	public function sentDocument(string $number, DateTimeInterface $since): ?array
	{
		$documents = $this->call('get', '/api/v2/document/outgoing', [
			'insertedFrom' => CarbonImmutable::instance($since)->subDay()->toIso8601String(),
			'limit' => 1000,
		]);

		$document = collect(array_is_list($documents) ? $documents : [$documents])
			->filter(fn ($row) => is_array($row) && (string) ($row['documentId'] ?? '') === $number && filled($row['id'] ?? null))
			->sortByDesc(fn (array $row) => (int) $row['id'])
			->first();

		return $document === null ? null : ['electronicId' => (string) $document['id'], 'status' => $this->documentStatus($document)];
	}

	public function status(string $electronicId): array
	{
		$document = $this->call('get', '/api/v2/document/status/' . rawurlencode($electronicId));

		if (blank($document['id'] ?? null)) {
			throw new RuntimeException('The intermediary does not know the document ' . $electronicId . '.');
		}

		$status = $this->documentStatus($document);
		[$fiscalization, $error] = $this->fiscalization($document);

		if ($status === DocumentStatus::REJECTED && $error === null) {
			$error = $this->rejectionReason($document);
		}

		return ['status' => $status, 'fiscalization' => $fiscalization, 'error' => $error];
	}

	public function reportPayment(string $electronicId, string $paidOn, float $amount): void
	{
		$this->call('post', '/api/v2/ereporting/paid/' . rawurlencode($electronicId), [
			'paymentDate' => $paidOn,
			'paidAmount' => round($amount, 2),
			'paymentType' => self::PAYMENT_BY_TRANSFER,
		]);
	}

	public function ping(): void
	{
		$account = $this->account();

		try {
			$response = $this->request($account)->get($this->serviceFor(self::SERVICES, $account) . '/api/v2/ping');
		} catch (ConnectionException $exception) {
			throw new EInvoiceUnavailableException($exception->getMessage());
		}

		// The service answers its ping only to a key it knows, so a refusal is the account's and an error the service's
		if ($response->serverError()) {
			throw new EInvoiceUnavailableException('HTTP ' . $response->status());
		}

		if (!$response->successful()) {
			throw new RuntimeException($this->failure($response));
		}
	}

	/**
	 * ePoslovanje's code of the document's standing as the package's.
	 *
	 * @param  array<string, mixed>  $document
	 */
	protected function documentStatus(array $document): DocumentStatus
	{
		$code = (int) ($document['status'] ?? 0);

		return match (true) {
			in_array($code, self::DELIVERED_CODES, true) => DocumentStatus::DELIVERED,
			in_array($code, self::REJECTED_CODES, true) => DocumentStatus::REJECTED,
			default => DocumentStatus::SENT,
		};
	}

	/**
	 * The tax authority's answer about the document itself, read off the latest of its fiscalization requests: taken
	 * (completed, or marked a success), refused with the tax authority's code and reason, or still waiting; nothing
	 * while ePoslovanje has made no such request.
	 *
	 * @param  array<string, mixed>  $document
	 * @return array{0: ?FiscalizationStatus, 1: ?string}
	 */
	protected function fiscalization(array $document): array
	{
		$latest = collect((array) ($document['eReportingRequests'] ?? []))
			->filter(fn ($request) => is_array($request) && (int) ($request['type'] ?? 0) === self::FISCALIZATION_REQUEST)
			->sortBy(fn (array $request) => (string) ($request['createdOn'] ?? ''))
			->last();

		if ($latest === null) {
			return [null, null];
		}

		if (($latest['success'] ?? null) === true || filled($latest['completedOn'] ?? null)) {
			return [FiscalizationStatus::FISCALIZED, null];
		}

		$code = trim((string) ($latest['errorCode'] ?? ''));
		$description = trim((string) ($latest['errorDescription'] ?? ''));

		if ($code !== '' || $description !== '') {
			return [FiscalizationStatus::FAILED, trim($code . ' ' . $description)];
		}

		return [FiscalizationStatus::WAITING, null];
	}

	/**
	 * Why the document will not reach the buyer: the note of its latest rejection or failed delivery, else that
	 * entry's text, else a plain word.
	 *
	 * @param  array<string, mixed>  $document
	 */
	protected function rejectionReason(array $document): string
	{
		$entry = collect((array) ($document['statusHistory'] ?? []))
			->filter(fn ($row) => is_array($row) && in_array((int) ($row['status'] ?? 0), self::REJECTED_CODES, true))
			->sortBy(fn (array $row) => (string) ($row['createdOn'] ?? ''))
			->last();

		$reason = trim((string) ($entry['note'] ?? '')) ?: trim((string) ($entry['statusText'] ?? ''));

		return $reason !== '' ? $reason : 'The e-invoice will not reach the buyer.';
	}

	/**
	 * A JSON call with the account's key; the intermediary's refusal becomes an exception with its message. A 400 to
	 * a refusable call (a send) is the document refused for good; anything else may pass on a later try.
	 *
	 * @param  array<string, mixed>  $payload
	 * @param  array<string, mixed>|null  $account
	 * @return array<int|string, mixed>
	 */
	protected function call(string $method, string $path, array $payload = [], ?array $account = null, bool $refusable = false): array
	{
		$account ??= $this->account();
		$url = $this->serviceFor(self::SERVICES, $account) . $path;

		try {
			$response = $method === 'get'
				? $this->request($account)->get($url, $payload)
				: $this->request($account)->post($url, $payload);
		} catch (ConnectionException $exception) {
			throw new RuntimeException($exception->getMessage());
		}

		if ($refusable && in_array($response->status(), [400, 422], true)) {
			throw new EInvoiceRefusedException($this->failure($response));
		}

		if (!$response->successful()) {
			throw new RuntimeException($this->failure($response));
		}

		$json = $response->json();

		return is_array($json) ? $json : [];
	}

	/**
	 * @param  array<string, mixed>  $account
	 */
	protected function request(array $account): PendingRequest
	{
		return Http::acceptJson()
			->withHeaders(['Authorization' => (string) $account['apiKey']])
			->timeout(max(1, $this->attemptSeconds))
			->connectTimeout(5);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function account(): array
	{
		return $this->accounts->account() ?? throw new RuntimeException('The intermediary account is not set up.');
	}

	/**
	 * Why the intermediary refused: its error with the details, else the start of the body.
	 */
	protected function failure(Response $response): string
	{
		$json = $response->json();
		$prefix = 'HTTP ' . $response->status() . ': ';

		if (is_array($json)) {
			$error = trim((string) ($json['error'] ?? ''));
			$details = trim((string) ($json['details'] ?? ''));
			$message = trim($error . ($details !== '' ? ' (' . $details . ')' : ''));

			if ($message !== '') {
				return $prefix . $message;
			}
		}

		return 'HTTP ' . $response->status() . ($response->body() !== '' ? ': ' . mb_substr(trim($response->body()), 0, 300) : '');
	}
}
