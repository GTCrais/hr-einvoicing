<?php

namespace GTCrais\HrEInvoicing\MojEracun;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use GTCrais\HrEInvoicing\Concerns\ChoosesEnvironment;
use GTCrais\HrEInvoicing\Concerns\RedactsSecrets;
use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceUnavailableException;
use GTCrais\HrEInvoicing\FiscalizationStatus;
use GTCrais\HrEInvoicing\MojEracun\Contracts\AccountProvider;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The line to Moj-eRačun's REST API, as its demo service answered on 2026-10-22: every call carries the account the
 * AccountProvider gives (the username and password, the issuer's OIB as the company, the business unit when there is
 * one and the software identifier), the document goes as the XML text of a JSON request, and the answers come back
 * as JSON whose keys are written now with a capital and now without, so they are read either way. A query answers
 * with a list; the tax authority's answers about an e-invoice come as a list of documents, each with its messages,
 * and as a 404 of Moj-eRačun's own while there are none yet. Moj-eRačun's status codes (20 taken in, 30 sent, 40
 * delivered, 50 not processed or not delivered, which is refused and final: the document was never exchanged, so the
 * tax authority never saw it and never will, as Moj-eRačun confirmed on 2026-10-27; 70 reported to the tax authority
 * because the buyer takes no e-invoices) become the package's DocumentStatus; no code is read as the buyer's rejection,
 * as the package asks Moj-eRačun for no processing status of the buyer's. The environment is "demo" or "production",
 * the account's own when it names one, and a call may take attemptSeconds; the service provider builds it from
 * config('hrEInvoicing'). A failure's message never carries the account's password.
 */
class MojEracunClient implements EInvoiceGateway
{
	use ChoosesEnvironment;
	use RedactsSecrets;

	/** The intermediary's services by environment */
	public const array SERVICES = [
		'demo' => 'https://demo.moj-eracun.hr',
		'production' => 'https://www.moj-eracun.hr',
	];

	/** The tax authority's answers about an e-invoice it was given: through the e-invoice exchange, or as an eReport */
	protected const array FISCALIZATION_MESSAGE_TYPES = [0, 4];

	/** The tax authority's answer to a report of the e-invoice's payment (markPaid) */
	protected const int PAYMENT_MESSAGE_TYPE = 3;

	public function __construct(
		protected AccountProvider $accounts,
		protected string $environment = 'demo',
		protected int $attemptSeconds = 15
	) {}

	public function ready(): bool
	{
		return $this->accountOf($this->accounts) !== null;
	}

	public function send(string $xml): array
	{
		$account = $this->account();
		$document = $this->post('/apis/v2/send', ['file' => $xml], refusable: true, account: $account);

		// A send it will not take for a condition of sending answers 200 without a document: one whose number, issuer,
		// buyer and year it holds already ("already exists with EID=…", the demo's words of 2026-10-26) is refused, as the
		// document itself is; any other condition a later try may pass
		$prerequisites = $this->value($document, 'PrerequisitesValidationFailed');

		if (is_array($prerequisites)) {
			$reason = 'HTTP 200: ' . (trim(implode(' ', array_map(strval(...), array_filter((array) ($this->value($prerequisites, 'Messages') ?? []), is_scalar(...))))) ?: 'the conditions for sending are not met');
			$reason = $this->redact($reason, $this->secrets($account));

			throw preg_match('/already exists|već postoji/iu', $reason) ? new EInvoiceRefusedException($reason) : new RuntimeException($reason);
		}

		$electronicId = $this->value($document, 'ElectronicId');

		if (blank($electronicId)) {
			throw new RuntimeException('The intermediary answered without a document identifier.');
		}

		return ['electronicId' => (string) $electronicId, 'status' => $this->documentStatus($this->value($document, 'StatusId'))];
	}

	/**
	 * Looked up in the outbox by the number the document carries (its InvoiceNumber filter, an exact match, and with
	 * the day of issue the year's, InvoiceYear, as Moj-eRačun advised on 2026-10-26), among the documents taken in from
	 * the day before the first try on, and, when given, issued that day ("Issued").
	 */
	public function sentDocument(string $number, DateTimeInterface $since, ?string $issueDate = null): ?array
	{
		$documents = $this->post('/apis/v2/queryOutbox', [
			'from' => CarbonImmutable::instance($since)->subDay()->toDateString(),
			'to' => CarbonImmutable::now()->addDay()->toDateString(),
			// Only that number, so the answer is never a page of the outbox the document may be missing from
			'InvoiceNumber' => $number,
			...($issueDate !== null ? ['InvoiceYear' => (int) substr($issueDate, 0, 4)] : []),
		], quietlyMissing: true);

		$document = collect(array_is_list($documents) ? $documents : [$documents])
			->filter(fn ($row) => is_array($row) && (string) $this->value($row, 'DocumentNr') === $number && filled($this->value($row, 'ElectronicId'))
				&& $this->issuedOn($this->value($row, 'Issued'), $issueDate))
			->sortByDesc(fn (array $row) => (int) $this->value($row, 'ElectronicId'))
			->first();

		if ($document === null) {
			return null;
		}

		// One it could not process or deliver ("Neuspjelo", 50) is refused, and final: the caller sends the corrected
		// document afresh under the same number, which Moj-eRačun's duplicate check allows for a document in 50 alone
		// (Moj-eRačun, 2026-10-27); the package never writes a CopyIndicator, and one absent says the document is no copy
		return ['electronicId' => (string) $this->value($document, 'ElectronicId'), 'status' => $this->documentStatus($this->value($document, 'StatusId'))];
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

		// One it could not process or deliver ("Neuspjelo", 50) was never exchanged, so the tax authority never saw it and
		// never will, no report of it exists, and the standing is final (Moj-eRačun, 2026-10-27); statusOutbox is not asked
		// about it, as the demo's mock messages would say otherwise
		if ($status === DocumentStatus::REFUSED) {
			return [
				'status' => DocumentStatus::REFUSED, 'fiscalization' => null, 'error' => trim((string) ($this->value($document, 'StatusName') ?? '')) ?: null, 'payment' => null, 'paymentError' => null, 'paymentReports' => 0, 'answersError' => null,
				'rejectionReason' => null, 'fiscalizationError' => null,
			];
		}

		// The tax authority's answers come from a call of their own: one that fails leaves the standing known and the
		// answers unknown (answersError), never read as none
		try {
			$messages = $this->taxAuthorityMessages($electronicId);
			$answersError = null;
		} catch (RuntimeException $exception) {
			$messages = [];
			$answersError = $exception->getMessage();
		}

		[$fiscalization, $error] = $this->latestAnswer($messages, self::FISCALIZATION_MESSAGE_TYPES);
		[$payment, $paymentError] = $this->latestAnswer($messages, [self::PAYMENT_MESSAGE_TYPE]);

		// Every payment report it took has a message of its own, answered or not
		$paymentReports = $answersError === null
			? collect($messages)->filter(fn (array $message) => (int) $this->value($message, 'messageType') === self::PAYMENT_MESSAGE_TYPE)->count()
			: null;

		// Moj-eRačun is asked for no processing status of the buyer's, so none of its documents is rejected
		return [
			'status' => $status, 'fiscalization' => $fiscalization, 'error' => $error, 'payment' => $payment, 'paymentError' => $paymentError, 'paymentReports' => $paymentReports, 'answersError' => $answersError,
			'rejectionReason' => null, 'fiscalizationError' => $error,
		];
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
		$account = $this->account();

		// The service answers its ping without the account; while it does not, or its answer is cut off on the way, the
		// account cannot be checked
		try {
			$response = Http::acceptJson()->timeout($this->seconds())->get($this->url('/apis/v2/Ping/', $account));
		} catch (HttpClientException $exception) {
			throw new EInvoiceUnavailableException($this->transportFailure($exception, $account));
		}

		if (!$response->successful()) {
			throw new EInvoiceUnavailableException('HTTP ' . $response->status());
		}

		// The account itself is proven by a query that needs it; an empty outbox answers 404 "Nema dokumenata.", which
		// the service gives only to an account it took
		$this->post('/apis/v2/queryOutbox', ['from' => CarbonImmutable::now()->subDay()->toDateString(), 'to' => CarbonImmutable::now()->toDateString()], quietlyMissing: true, account: $account);
	}

	/**
	 * Whether a document the outbox says was issued then ("2026-01-28T00:00:00") is of the day asked for; any is when
	 * no day is asked for or the outbox names none.
	 */
	protected function issuedOn(mixed $issued, ?string $issueDate): bool
	{
		return $issueDate === null || blank($issued) || substr((string) $issued, 0, 10) === $issueDate;
	}

	/**
	 * Moj-eRačun's status code of a document as the package's standing: 50, "Neuspjelo", is refused and final, never
	 * exchanged nor fiscalized, and sent again corrected under its number (Moj-eRačun, 2026-10-27).
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
	 * The tax authority's answers about the e-invoice, each with its type; none while it has given none (the service's
	 * own 404). Any other failure is thrown, a 404 that is not Moj-eRačun's own answer included: a standing without the
	 * answers would tell a payment already reported as one never reported, and an app that reports it again on that
	 * reports the money twice.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function taxAuthorityMessages(string $electronicId): array
	{
		$documents = $this->post('/api/fiscalization/statusOutbox', ['electronicId' => (int) $electronicId], quietlyMissing: true);

		return collect(array_is_list($documents) ? $documents : [$documents])
			->filter(fn ($row) => is_array($row) && (string) $this->value($row, 'electronicId') === $electronicId)
			->flatMap(fn (array $row) => (array) ($this->value($row, 'messages') ?? []))
			->filter(fn ($message) => is_array($message))
			->values()
			->all();
	}

	/**
	 * The latest of the tax authority's answers of the given types: taken, refused with its code and reason, or
	 * waiting; nothing while there is none.
	 *
	 * @param  list<array<string, mixed>>  $messages
	 * @param  list<int>  $types
	 * @return array{0: ?FiscalizationStatus, 1: ?string}
	 */
	protected function latestAnswer(array $messages, array $types): array
	{
		$latest = collect($messages)
			->filter(fn (array $message) => in_array((int) $this->value($message, 'messageType'), $types, true))
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
	 * The reason the tax authority gave for refusing, with its code; null when it gave none.
	 *
	 * @param  array<string, mixed>  $message
	 */
	protected function messageError(array $message): ?string
	{
		$code = trim((string) $this->value($message, 'errorCode'));
		$description = trim((string) ($this->value($message, 'errorCodeDescription') ?: $this->value($message, 'message')));

		return trim($code . ' ' . $description) ?: null;
	}

	/**
	 * A JSON call with the account (the one given, else the provider's); the intermediary's refusal becomes an exception
	 * with its message. A 404 of Moj-eRačun's own is no error for the calls that answer it while they have nothing to
	 * tell, and comes back empty; any other 404 (a proxy's page, a route moved) is thrown, as it says nothing of what
	 * Moj-eRačun holds. A call that got no whole answer (none at all, or one cut off on its way) may pass on a later try.
	 *
	 * @param  array<string, mixed>  $payload
	 * @param  array<string, mixed>|null  $account
	 * @return array<int|string, mixed>
	 */
	protected function post(string $path, array $payload, bool $quietlyMissing = false, bool $refusable = false, ?array $account = null): array
	{
		$account ??= $this->account();
		$url = $this->url($path, $account);

		try {
			$response = Http::acceptJson()
				->timeout($this->seconds())
				->connectTimeout(5)
				// The service's environment is the account's affair, not the service's
				->post($url, [...array_diff_key($account, ['environment' => true]), ...$payload]);
		} catch (HttpClientException $exception) {
			throw new RuntimeException($this->transportFailure($exception, $account));
		}

		if ($quietlyMissing && $response->status() === 404 && $this->ownAnswer($response)) {
			return [];
		}

		// A document it could not take is refused for good; anything else, a refusal of the account included (a wrong
		// software identifier, a password), and any other call it refuses, may pass on a later try
		if ($refusable && in_array($response->status(), [400, 422], true) && $this->refusesDocument($response)) {
			throw new EInvoiceRefusedException($this->failure($response, $account));
		}

		if (!$response->successful()) {
			throw new RuntimeException($this->failure($response, $account));
		}

		$json = $response->json();

		return is_array($json) ? $json : [];
	}

	/**
	 * Whether an answer is Moj-eRačun's own JSON, as it words a 404 while it has nothing to tell: an object with its
	 * message or its trace identifier ({"message": "Nema dokumenata.", "traceId": …}).
	 */
	protected function ownAnswer(Response $response): bool
	{
		$json = $response->json();

		return is_array($json) && !array_is_list($json)
			&& (is_string($this->value($json, 'message')) || is_string($this->value($json, 'traceId')));
	}

	/**
	 * Why a call got no whole answer: a RequestException carries what arrived of it (a status, and the body as far as it
	 * came), read as any other refusal; any other failure says itself. Never with the account's password.
	 *
	 * @param  array<string, mixed>  $account
	 */
	protected function transportFailure(HttpClientException $exception, array $account): string
	{
		return $exception instanceof RequestException
			? $this->failure($exception->response, $account)
			: $this->redact($exception->getMessage(), $this->secrets($account));
	}

	/**
	 * The account to call with; a RuntimeException while the provider gives none.
	 *
	 * @return array<string, mixed>
	 */
	protected function account(): array
	{
		return $this->accountOf($this->accounts) ?? throw new RuntimeException('The intermediary account is not set up.');
	}

	/**
	 * What of the account must never be said: its password.
	 *
	 * @param  array<string, mixed>|null  $account
	 * @return list<mixed>
	 */
	protected function secrets(?array $account): array
	{
		return [$account['password'] ?? null];
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
	 * Whether a refusal is of the document itself: the rules it broke (Moj-eRačun's "File" messages, a business rule
	 * of the specification) or the tax authority's code, not a field of the account.
	 */
	protected function refusesDocument(Response $response): bool
	{
		$json = $response->json();

		if (is_array($json)) {
			$file = $this->value($json, 'File');
			$specifics = $this->value($json, 'taxAdministrationSpecifics');

			if ((is_array($file) && (array) ($this->value($file, 'Messages') ?? []) !== []) || (is_array($specifics) && filled($this->value($specifics, 'code')))) {
				return true;
			}
		}

		return (bool) preg_match('/\b(HR-)?BR-[A-Z0-9-]+/', $response->body());
	}

	/**
	 * Why the intermediary refused: the rules the document broke, the tax authority's code and reason, the message, or
	 * the fields it refused with their messages, else the start of the body; never with the account's password.
	 *
	 * @param  array<string, mixed>|null  $account
	 */
	protected function failure(Response $response, ?array $account): string
	{
		$json = $response->json();
		$reason = is_array($json) ? $this->answerReason($json) : null;

		if ($reason !== null) {
			return 'HTTP ' . $response->status() . ': ' . $this->redact($reason, $this->secrets($account));
		}

		$excerpt = $this->bodyExcerpt($response->body(), $this->secrets($account));

		return 'HTTP ' . $response->status() . ($excerpt !== '' ? ': ' . $excerpt : '');
	}

	/**
	 * What an answer of Moj-eRačun says of why it refused, if anything: the rules the document broke ("File"), the tax
	 * authority's code and reason with the message, the message, or the fields it refused.
	 *
	 * @param  array<int|string, mixed>  $json
	 */
	protected function answerReason(array $json): ?string
	{
		$file = $this->value($json, 'File');
		$rules = is_array($file) ? array_filter((array) ($this->value($file, 'Messages') ?? []), is_scalar(...)) : [];

		if ($rules !== []) {
			return implode(' ', array_map(strval(...), $rules));
		}

		$message = $this->value($json, 'message') ?? $this->value($json, 'error') ?? $this->value($json, 'title');
		$message = is_string($message) || $message === null ? $message : json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$specifics = $this->value($json, 'taxAdministrationSpecifics');

		if (is_array($specifics) && filled($this->value($specifics, 'code'))) {
			return trim($this->value($specifics, 'code') . ' ' . $this->value($specifics, 'description') . (filled($message) ? ' (' . $message . ')' : ''));
		}

		if (filled($message)) {
			return $message;
		}

		return $this->refusedFields($json);
	}

	/**
	 * The fields an answer refuses, in Moj-eRačun's shape for a request it will not take ({"SoftwareId": {"Value": …,
	 * "Messages": […]}}), as "SoftwareId: its messages", fields apart by "; ": never the value it echoes with them, which
	 * for the password is the password; null when the answer refuses no field.
	 *
	 * @param  array<int|string, mixed>  $json
	 */
	protected function refusedFields(array $json): ?string
	{
		$fields = [];

		foreach ($json as $field => $answer) {
			if (!is_string($field) || !is_array($answer) || ($this->value($answer, 'Messages') === null && $this->value($answer, 'Value') === null)) {
				continue;
			}

			$messages = trim(implode(' ', array_map(strval(...), array_filter((array) ($this->value($answer, 'Messages') ?? []), is_scalar(...)))));
			$fields[] = $field . ($messages !== '' ? ': ' . $messages : '');
		}

		return $fields === [] ? null : implode('; ', $fields);
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
