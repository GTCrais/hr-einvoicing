# HR e-invoicing for Laravel

Croatian e-invoices (Fiskalizacija 2.0) for Laravel: write UBL 2.1 documents under the Croatian CIUS 2025 and send
them through an information intermediary, Moj-eRačun or ePoslovanje.

*Hrvatski e-računi (Fiskalizacija 2.0) za Laravel: UBL 2.1 dokumenti prema HR CIUS 2025, slanje putem Moj-eRačuna ili
ePoslovanja.*

The package gives you:

- **A client for each intermediary**, Moj-eRačun and ePoslovanje, behind one gateway contract: send a document, look
  up its status and the tax authority's answer, find a document whose answer was lost, report a payment, test the
  connection. Every answer speaks the package's own statuses, whatever the intermediary's codes, so your app does not
  care which one carried a document.
- **The parts every Croatian e-invoice shares**, written the way Moj-eRačun's examples write them: the document with its
  namespaces, the Croatian extension (`HRFISK20Data`), the parties by OIB, the VAT breakdown, the totals and the lines.
  You map your own invoices onto them.
- **For your tests**: a fake gateway, and a check of documents against the UBL 2.1 schemas with the Croatian
  extension.

Invoices, stornos, advance invoices and credit notes written with it have passed Moj-eRačun's validation on its demo
service.

## Requirements

- PHP 8.3+ with the `dom` and `libxml` extensions
- Laravel 12 or 13
- An account with Moj-eRačun (and the software identifier Moj-eRačun issues for your software) or with ePoslovanje
  (whose API key the account's control panel shows)

## Installation

```bash
composer require gtcrais/hr-einvoicing
php artisan vendor:publish --tag=hr-einvoicing-config
```

The service provider is discovered automatically.

## Configuration

`config/hrEInvoicing.php` reads these from `.env`:

| Variable | Meaning |
| --- | --- |
| `HR_EINVOICING_GATEWAY` | `mojeracun` (default), `eposlovanje`, or `fake` for tests |
| `HR_EINVOICING_FAKE` | `false` (default); `true` has the fake answer for every intermediary, as that intermediary, while the gateway keeps naming yours; never in production, where the fake is refused |
| `HR_EINVOICING_ALLOW_PRODUCTION_ANYWHERE` | `false` (default): the production services are refused unless the app runs with `APP_ENV=production` |
| `MOJ_ERACUN_ENVIRONMENT` | `demo` (default) or `production` |
| `MOJ_ERACUN_USERNAME` | The Moj-eRačun account's username |
| `MOJ_ERACUN_PASSWORD` | Its password |
| `MOJ_ERACUN_OIB` | The issuer's OIB |
| `MOJ_ERACUN_BUSINESS_UNIT` | The issuer's business unit, if it has one |
| `MOJ_ERACUN_SOFTWARE_ID` | The software identifier Moj-eRačun issued |
| `EPOSLOVANJE_ENVIRONMENT` | `demo` (default, the test server) or `production` |
| `EPOSLOVANJE_API_KEY` | The API key of the ePoslovanje account; a test account's key opens only the test server |
| `EPOSLOVANJE_OIB` | The issuer's OIB |
| `EPOSLOVANJE_SOFTWARE_ID` | A software identifier of your own choosing |

`attemptSeconds` (15 by default) caps each call to the intermediary.

Every intermediary has a demo (test) service and a production one, with different accounts on each. A development
machine never reaches a production service, whatever its `.env` holds, unless `HR_EINVOICING_ALLOW_PRODUCTION_ANYWHERE`
is set; a call that would is refused with a `RuntimeException`.

## Sending e-invoices

Resolve the gateway from the container, or ask `GatewayManager` for an intermediary by name:

```php
use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;
use GTCrais\HrEInvoicing\GatewayManager;

$gateway = app(EInvoiceGateway::class);               // the one HR_EINVOICING_GATEWAY names
$gateway = app(GatewayManager::class)->driver('eposlovanje');

try {
    ['electronicId' => $id, 'status' => $status] = $gateway->send($xml);
} catch (EInvoiceRefusedException $exception) {
    // The intermediary refused the document itself (the rules it broke, or the tax authority's code);
    // the message says why. Sending the same document again would be refused again. A refusal of the
    // account (not active, no funds, an unknown key, a wrong software identifier) is a RuntimeException
    // instead: it passes once the account is put right.
} catch (RuntimeException $exception) {
    // The intermediary could not be reached or answered with an error; a later try may pass.
}
```

| Method | What it does |
| --- | --- |
| `ready(): bool` | Whether the account is complete |
| `send(string $xml): array` | Sends the document; returns `electronicId` and its `DocumentStatus` |
| `sentDocument(string $number, DateTimeInterface $since, ?string $issueDate = null): ?array` | Finds a document sent since then by its number, for a send whose answer was lost; numbers start again every year, so pass the day of issue (`cbc:IssueDate`, Y-m-d) to tell one year's document from the next |
| `status(string $electronicId): array` | The document's `DocumentStatus`; the tax authority's answer about it, `fiscalization` (a `FiscalizationStatus`, or null while the intermediary has said nothing) with its `error`; and its answer to the latest payment report, `payment` with `paymentError`. The reasons are null where the intermediary gave none. When the standing is known but the tax authority's answers cannot be read (Moj-eRačun reads them with a second call), `answersError` says why and `fiscalization` and `payment` are unknown rather than none; take a null `payment` for a report never made only when `answersError` is null |
| `reportPayment(string $electronicId, string $paidOn, float $amount): void` | Reports a payment by bank transfer to the tax authority. Moj-eRačun answers with the tax authority's word at once (a refusal is thrown); ePoslovanje queues the report and the answer comes later in `status()`'s `payment` |
| `ping(): void` | Tests the connection, and on ePoslovanje that the account fiscalizes its outgoing documents itself; throws `EInvoiceUnavailableException` when the service itself is down |

A gateway that also implements `Contracts\FiscalizesAgain` (ePoslovanje, and the fake as ePoslovanje or under its own name) takes `fiscalizeAgain($electronicId)`:
a new request to the tax authority for a document it refused, once the cause is put right (with S006, the issuer had not
authorized the intermediary in the FiskAplikacija).

`DocumentStatus` is `SENT` (with the intermediary, on its way), `DELIVERED` (the buyer has it, approved or paid too),
`REPORTED` (reported to the tax authority instead, because the buyer takes no e-invoices; Moj-eRačun does this),
`REFUSED` (the intermediary did not take it; a new try sends it afresh) or `REJECTED` (the intermediary holds it, but it
will not reach the buyer: the buyer rejected it, or its delivery failed for good; `isHeld()` tells the last two apart).
Moj-eRačun's "Neuspjelo" (50) is an error in processing or in delivery, which the tax authority's answer tells apart:
once it fiscalized the document it is `REJECTED`, held, and never sent twice; while the answer is still awaited, or the
answers cannot be read, it is followed as `SENT`; it is `REFUSED` only when the tax authority refused it or was never
asked about it.
`FiscalizationStatus` is `FISCALIZED`, `FAILED` (with the tax authority's code and reason in `error`) or `WAITING`. On
ePoslovanje a fiscalization counts as taken only when it is marked a success (it marks a refused one completed too), and
once taken it stays taken whatever a later duplicate request says.

Every call throws a `RuntimeException` with the reason when it fails.

### One account per tenant

The calls carry the configured account. An app that sends for many companies binds its own account provider instead,
one per intermediary. The client asks it again before every call, and an account may name its own `environment`, so one
tenant can be on the demo service while another is live:

```php
use GTCrais\HrEInvoicing\MojEracun\Contracts\AccountProvider;

class TenantMojEracunAccount implements AccountProvider
{
    public function account(): ?array
    {
        $tenant = tenant();

        if (blank($tenant->moj_eracun_username)) {
            return null;
        }

        return [
            'username' => $tenant->moj_eracun_username,
            'password' => $tenant->moj_eracun_password,
            'companyId' => $tenant->oib,
            'companyBU' => null,
            'softwareId' => config('hrEInvoicing.mojEracun.softwareId'),
            'environment' => $tenant->live ? 'production' : 'demo',
        ];
    }
}

// In a service provider's register()
$this->app->bind(AccountProvider::class, TenantMojEracunAccount::class);
$this->app->bind(\GTCrais\HrEInvoicing\EPoslovanje\Contracts\AccountProvider::class, TenantEPoslovanjeAccount::class);
```

An ePoslovanje account is `['apiKey' => …, 'oib' => …, 'softwareId' => …]`, with the same optional `environment`.

When a tenant's intermediary or service can change, keep both with every document you send and follow the document up
where it went: `GatewayManager::driver($name, $environment)` hands out a client held to that service.
`GatewayManager::INTERMEDIARIES` lists the names. A provider that keeps an account per service implements
`Contracts\AccountsByEnvironment` (`accountFor($environment)`), and the held client calls with that service's account;
the configured providers answer only for the service they are configured for, so a document of the other service has
no account to go with (`ready()` is false).

## Writing documents

Use the `WritesHrUbl` trait in a class that maps your invoices onto the document. UBL fixes the order of the elements,
so call the methods in this order:

```php
use GTCrais\HrEInvoicing\Ubl\Concerns\WritesHrUbl;
use GTCrais\HrEInvoicing\Ubl\HrUbl;

class InvoiceXml
{
    use WritesHrUbl;

    public function build(Invoice $invoice): string
    {
        $lines = $this->lines($invoice); // your mapping, see below
        $categories = $this->categories($lines);
        $net = round(array_sum(array_column($lines, 'net')), 2);
        $tax = round(array_sum(array_column($lines, 'tax')), 2);
        $gross = round($net + $tax, 2);

        $root = $this->document();

        $this->extensions($root, $categories, $net, $tax, 'EUR', vatRegistered: true);
        $this->heading($root, HrUbl::PROFILE_INVOICE, $invoice->number, $invoice->issued_at->setTimezone('Europe/Zagreb'));
        $this->leaf($root, 'cbc:DueDate', $invoice->due_on->toDateString());
        $this->leaf($root, 'cbc:InvoiceTypeCode', (string) HrUbl::TYPE_INVOICE);
        $this->leaf($root, 'cbc:DocumentCurrencyCode', 'EUR');
        $this->supplierParty($root, $this->seller(), vatRegistered: true, operatorOib: '12345678903', operatorName: 'Ana Horvat');
        $this->customerParty($root, $this->buyer($invoice));
        $this->delivery($root, $invoice->delivered_on->toDateString());
        $this->paymentMeans($root, $invoice->number, 'HR1210010051863000160');
        $this->taxTotal($root, $categories, $tax, 'EUR');
        $this->monetaryTotal($root, $net, $gross, $gross, 'EUR');

        foreach ($lines as $line) {
            $this->line($root, $line, 'EUR');
        }

        return (string) $root->ownerDocument->saveXML();
    }
}
```

The arrays the methods take:

- **A party**: `oib`, `name` (the legal name), `street`, `city`, `postalCode`, `country` (`HR` for a Croatian
  company, which also gets its VAT identifier).
- **A line**: `id` (1, 2, …), `name`, `quantity`, `net` (after discounts), `tax`, `unit` (the net unit price), `kpd`
  (the KPD 2025 classification code, or null) and `category`.
- **A VAT category**: `id` (`S` standard, `E` exempt), `name` (`HR:PDV25`, `HR:E`), `percent`, and for an exemption
  its `code` (VATEX) and `reason`; a `key` may come along, which `categories()` sets itself. `categories()` adds up each
  category's `base` and `tax` from the lines and puts the standard rates first. EN 16931 takes one breakdown per
  standard rate and exactly one for every other category (BR-E-01), so lines exempt on different grounds share one
  breakdown: its reason names every ground (joined with "; "), and it keeps the VATEX code only when every line has
  the same one. Each line still carries its own code and reason.

For the other kinds of documents:

- **A storno** is `HrUbl::TYPE_CORRECTIVE` under `HrUbl::PROFILE_CORRECTIVE`, with negative quantities and amounts and
  a `billingReference()` to the invoice it cancels.
- **An advance invoice** is `HrUbl::TYPE_ADVANCE` under `HrUbl::PROFILE_ADVANCE`. Pass what was paid as
  `monetaryTotal()`'s `prepaid`.
- **A credit note** is a `CreditNote` document: call `document(creditNote: true)`, write `cbc:CreditNoteTypeCode` in
  place of the due date and the invoice type code, and pass `creditNote: true` to `line()`.

An issuer outside the VAT system passes `vatRegistered: false`, gives every line an `E` category with the reason, and is
named by its OIB under the `FRE` scheme. The Croatian extension is written whenever a category is not standard.

The intermediaries sign the documents, so the signature block is left empty for them.

## Testing

Set `HR_EINVOICING_GATEWAY=fake` in `phpunit.xml`, or keep the gateway naming your intermediary and set
`HR_EINVOICING_FAKE=true`. The gateway (`app(EInvoiceGateway::class)`, and `driver()` without a name) is then a
`Testing\FakeEInvoiceGateway`, one for the whole run, which stands for no intermediary. `GatewayManager::driver($name, $environment)` hands it out as
that intermediary on that service (`Testing\FakeIntermediary`; a name the package does not know is refused, as without
the fake), so an app's tests see each intermediary as it is:

- it can do what the real client can: `FiscalizesAgain` only as ePoslovanje (and under the fake's own name);
- it is ready, and calls, only where the real client's account provider gives an account, by the same rules, and its
  production service is refused outside a production installation, as the real one is;
- every call is recorded with the intermediary and the service it went to;
- a document is found only through the intermediary and the service it was sent through; asking another for it finds
  nothing, as the real one knows nothing of it.

The fake itself, as a test asks it, stands for no intermediary and sees everything:

```php
use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\FiscalizationStatus;
use GTCrais\HrEInvoicing\Testing\UblSchema;

beforeEach(function () {
    $this->gateway = app(EInvoiceGateway::class);
    $this->gateway->reset();
});

it('sends the invoice', function () {
    $this->gateway->failNext();        // the next call fails as a timeout would
    $this->gateway->refuseNext();      // the next document is refused
    $this->gateway->loseNextAnswer();  // the next document arrives, but its answer is lost
    $this->gateway->setStatus('1001', DocumentStatus::DELIVERED, FiscalizationStatus::FISCALIZED);

    // ... send

    expect($this->gateway->sent)->toHaveCount(1)
        ->and(UblSchema::errors($this->gateway->sent[0]))->toBe([]);
});
```

With `ready` false every call is refused as without an account, and `status()`, `reportPayment()` and
`fiscalizeAgain()` know only the documents sent through it or given a standing with `setStatus()`.

The fake also records `payments`, `pings`, the documents asked to be fiscalized again (`refiscalized`), and every call
with the intermediary and the service it stood in for (`calls`), so a test can see a document followed up where it went.
`paymentAnswer` is what `status()` tells of a payment once it is reported (fiscalized unless a test says otherwise).

`UblSchema::errors($xml)` checks a document against the UBL 2.1 Invoice or CreditNote schema with the Croatian
extension and returns what is wrong, or an empty array.

## What it does not do

- It issues e-invoices; it does not receive them.
- It talks to Moj-eRačun and ePoslovanje. `EInvoiceGateway` leaves room for other intermediaries.
- ePoslovanje refuses a buyer that is not in the tax authority's address registry (AMS) at send, where Moj-eRačun reports
  the invoice to the tax authority itself (`REPORTED`). The package does not report such an invoice through ePoslovanje
  instead (its `/ereporting/reportdocument`, type `IR`): its test server does not check the registry, so the path could
  not be tried. The refusal comes back as the document's; report the invoice another way.
- It writes nothing outside the scope of VAT.

## Schemas

The XML schemas in `resources/ubl` are OASIS's UBL 2.1 and the Croatian Tax Administration's extension, included
unchanged. They keep their own terms and are not covered by this package's license; see
[resources/ubl/NOTICE.md](resources/ubl/NOTICE.md).

## License

MIT. See [LICENSE.md](LICENSE.md).
