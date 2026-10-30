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
| `send(string $xml): array` | Sends the document; returns `electronicId` and its `DocumentStatus`. A document whose number the intermediary holds already for the year (ePoslovanje's "already exists", Moj-eRačun's "already exists with EID=…") is refused with an `EInvoiceRefusedException`, as a document breaking the rules is; an app that sent it minutes after a try whose answer was lost looks it up instead |
| `sentDocument(string $number, DateTimeInterface $since, ?string $issueDate = null): ?array` | Finds a document sent since then by its number, for a send whose answer was lost; numbers start again every year, so pass the day of issue (`cbc:IssueDate`, Y-m-d) to tell one year's document from the next. Moj-eRačun is asked for that number of that year alone (its `InvoiceNumber` and `InvoiceYear` filters), so the answer is never a page of the outbox the document may be missing from |
| `status(string $electronicId): array` | The document's `DocumentStatus`; the tax authority's answer about it, `fiscalization` (a `FiscalizationStatus`, or null while the intermediary has said nothing) with its reason in `fiscalizationError`; and its answer to the latest payment report, `payment` with `paymentError`; and `paymentReports`, how many payment reports the intermediary holds of the document (every one it took, those the tax authority refused included), which tells an app that reports more than one payment of a document (one taken back with a negative report, then reported again) whether a report whose answer was lost reached it. `error` is the reason to keep for the standing: for a `REJECTED` document why the buyer rejected it, when it said (`rejectionReason`), for an `UNDELIVERED` one the intermediary's words about the failed delivery, for a `REFUSED` one the intermediary's reason, else the tax authority's (`fiscalizationError`). `deliveryRetriesUntil` (an ISO 8601 moment, or null) is set while the intermediary still retries a delivery that failed, the document staying `SENT`; `accepted` is true once the buyer approved or paid the document, after which no rejection comes. The reasons are null where the intermediary gave none. When the standing is known but the tax authority's answers cannot be read (Moj-eRačun reads them with a second call), `answersError` says why and `fiscalization`, `payment`, their reasons and `paymentReports` are unknown rather than none; take a null `payment` for a report never made only when `answersError` is null |
| `reportPayment(string $electronicId, string $paidOn, float $amount): void` | Reports a payment by bank transfer to the tax authority. Moj-eRačun answers with the tax authority's word at once, ePoslovanje queues the report and the answer comes later in `status()`'s `payment`. A report either refuses on the spot (HTTP 400 or 422: the tax authority's refusal passed on, a report it cannot take) is thrown as a `PaymentReportRefusedException`, for the app to word as a refusal; a failure of the line or of the service stays a plain `RuntimeException` |
| `ping(): void` | Tests the connection with the account, and on ePoslovanje that the account fiscalizes its outgoing documents itself; throws `EInvoiceUnavailableException` when the service itself is down (no answer, an answer cut off on its way, or a server error), to the ping or to the call that checks the account after it, so the account could not be tried; a refusal of the account is a `RuntimeException` |

A gateway that also implements `Contracts\FiscalizesAgain` (ePoslovanje, and the fake as ePoslovanje or under its own name) takes `fiscalizeAgain($electronicId)`:
a new request to the tax authority for a document it refused, once the cause is put right (with S006, the issuer had not
authorized the intermediary in the FiskAplikacija).

A gateway that also implements `Contracts\ListsBusinessUnits` (ePoslovanje, and the fake as ePoslovanje or under its own
name) takes `businessUnits($oib)`: the business units a company receives e-invoices under, as the tax authority's AMS
lists them, each with its `name` and its `code`, the unit's own code (ePoslovanje lists `9934:OIB::HR99:1200`, the
package gives `1200`), for a buyer's `businessUnit` (see Writing documents).

`DocumentStatus` is `SENT` (with the intermediary, on its way), `DELIVERED` (the buyer has it, approved or paid too),
`REPORTED` (reported to the tax authority instead, because the buyer takes no e-invoices; Moj-eRačun does this),
`REFUSED` (the intermediary did not take it; a new try sends it afresh), `REJECTED` (the buyer rejected it after it
received it: the document was exchanged and the tax authority has it, so a storno or a credit note puts it right, never
a new send, as Moj-eRačun said on 2026-10-30) or `UNDELIVERED` (the intermediary could not deliver it to the buyer's and
stopped trying: never fiscalized, it needs no storno; delete it in the intermediary's web application and send it
again, corrected, under the same number, as ePoslovanje advised on 2026-10-30, the buyer's business unit being the
usual cause; `isHeld()` is true for it, as the intermediary holds it until then).
Moj-eRačun's "Neuspjelo" (50) is final: the document was never exchanged, so the tax authority never saw it and never
will (Moj-eRačun, 2026-10-27). It is `REFUSED`, and the corrected document goes again under the same number, which
Moj-eRačun's duplicate check allows for a document in 50 alone.
A Moj-eRačun document is `REJECTED` once the tax authority recorded the buyer's rejection (a fiscalization message of
type 2 it took) or, for a delivered one, once the buyer set its process status to rejected
(`queryDocumentProcessStatusOutbox`, `DocumentProcessStatusId` 1, with its `RejectReason`); the process status also
tells `accepted` (0 approved, 2 paid, 3 partly paid), and one that cannot be read leaves the verdict to the next read.
ePoslovanje's codes 5, 7 and 8 are `accepted`, 6 is `REJECTED`, and 9 (delivery failed) is `SENT` with
`deliveryRetriesUntil` while ePoslovanje retries, for `EPoslovanjeClient::DELIVERY_RETRY_DAYS` (7) after it took the
document, and `UNDELIVERED` after that. The law sets no time for a buyer's rejection, which mostly comes after the tax
authority took the document, so follow a delivered one until it is `accepted` or as long as you choose.
`FiscalizationStatus` is `FISCALIZED`, `FAILED` (with the tax authority's code and reason in `fiscalizationError`, and in
`error` unless the document is `REJECTED`) or `WAITING`. On
ePoslovanje a fiscalization counts as taken only when it is marked a success (it marks a refused one completed too), and
once taken it stays taken whatever a later duplicate request says.

Every call throws a `RuntimeException` with the reason when it fails, an answer cut off on its way included. The reason
never carries the account's password or API key, whatever the intermediary echoed back: a Moj-eRačun answer about the
fields it refused is read as each field and its messages, without the value it repeats. A 404 from Moj-eRačun counts
as "nothing yet" (no answer of the tax authority, an empty outbox) only when it is Moj-eRačun's own answer, its message
in words of its own with at most a trace identifier; any other 404 (a proxy's page, ASP.NET Core's ProblemDetails, a
gateway's `{"message": "Not Found"}`) is thrown as a failure.

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
where it went: `GatewayManager::driver($name, $environment)` hands out a client held to that service (a blank one names
none, and the client follows the account's own service, as `driver($name)` does).
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

        // An earlier invoice it refers to, here an advance it takes off; a storno's or a credit note's goes here too
        if ($invoice->advance !== null) {
            $this->billingReference($root, $invoice->advance->number, $invoice->advance->issued_at->setTimezone('Europe/Zagreb')->toDateString());
        }

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
  a `billingReference()` to the invoice it cancels, called where the example calls it: after `cbc:DocumentCurrencyCode`
  and before `supplierParty()`, as UBL puts the reference before the parties (called after them, the schema refuses it).
- **An advance invoice** is `HrUbl::TYPE_ADVANCE` under `HrUbl::PROFILE_ADVANCE`. Pass what was paid as
  `monetaryTotal()`'s `prepaid`.
- **A credit note** is a `CreditNote` document: call `document(creditNote: true)`, write `cbc:CreditNoteTypeCode` in
  place of the due date and the invoice type code, give it a `billingReference()` to the invoice it takes back from in
  the same place as a storno's (after `cbc:DocumentCurrencyCode`, before `supplierParty()`), and pass `creditNote: true`
  to `line()`.

An issuer outside the VAT system passes `vatRegistered: false`, gives every line an `O` category (`HR:O`, outside the
scope of VAT) with the reason, as Moj-eRačun's example of an issuer under the `FRE` scheme does, is named by its OIB
under that scheme, and names its buyer the same way (`customerParty(withVatIdentifier: false)`), as such an invoice
takes no buyer VAT identifier (BR-O-02). An `O` line carries no rate (BR-O-05), the invoice no other category (BR-O-11),
and the Croatian extension puts its amount under `OutOfScopeOfVATAmount`. The Croatian extension is written whenever a
category is not standard, and for an issuer in the VAT system that pays VAT on what it was paid
(`extensions(cashBasis: true)`, "postupak oporezivanja prema naplaćenim naknadama"), whose invoices state it: the
extension then opens with `HRObracunPDVPoNaplati` (HR-BT-15), `HrUbl::CASH_BASIS_NOTE`, whose presence alone says so,
so it is never written otherwise (and never for an issuer outside the VAT system, which cannot be on the scheme).
Otherwise a Croatian buyer is named by its VAT identifier, `HR` and its OIB, whether or not it
is in the VAT system: the rules HR-BR-S-1 and HR-BR-E-1 ask for it on every invoice with a line at a standard rate or
exempt.

A buyer that receives e-invoices by business unit (registered so in the tax authority's AMS) is reached through it:
give `customerParty()`'s party a `businessUnit`, the unit's own code or the whole `9934:OIB::HR99:<code>` an AMS
listing gives, which `HrUbl::businessUnitCode()` takes down to the code (refusing another company's, or one with a
space or a colon, with an `InvalidArgumentException`). The party's identifier (BT-46) is then
`9934:OIB::HR99:<code>` (`HrUbl::BUSINESS_UNIT_SCHEME`), its `EndpointID` the bare OIB as ever. A missing or wrong
unit is the usual reason a delivery fails.

The intermediaries sign the documents, so the signature block is left empty for them.

Text goes in as XML 1.0 takes it, whatever was typed or pasted: a character XML forbids (a control character, such as
the line break Word pastes, U+000B) is left out, the vertical tab and the form feed become a space, and bytes that are
not UTF-8 become `?`, so one stray character in a service's or a buyer's name never makes a document no intermediary can
read. A value blank once trimmed is no value: its element is left out, and so is a part none of whose elements was
written (the operator's `SellerContact` without the operator, the `Delivery` without its day); `billingReference()`
writes nothing without the number. A part you add yourself with `child()` is written at once; when it may end up empty,
`pruneEmpty($part)` leaves it out. The IBAN alone cannot be left out: a payment by credit transfer (code 30) names the
account it is paid to (EN 16931 BR-61), so `paymentMeans()` refuses a blank one with an `InvalidArgumentException`
rather than write a document the intermediary would refuse.

## Testing

Set `HR_EINVOICING_GATEWAY=fake` in `phpunit.xml`, or keep the gateway naming your intermediary and set
`HR_EINVOICING_FAKE=true`. The gateway (`app(EInvoiceGateway::class)`, and `driver()` without a name) is then a
`Testing\FakeEInvoiceGateway`, one for the whole run, which stands for no intermediary. `GatewayManager::driver($name, $environment)` hands it out as
that intermediary on that service (`Testing\FakeIntermediary`; a name the package does not know is refused, as without
the fake), so an app's tests see each intermediary as it is:

- it can do what the real client can: `FiscalizesAgain` and `ListsBusinessUnits` only as ePoslovanje (and under the
  fake's own name);
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
    $this->gateway->refuseNext();      // the next document sent is refused
    $this->gateway->unavailableNext(); // the next connection test finds the service down
    $this->gateway->loseNextAnswer();  // the next document arrives, but its answer is lost
    $this->gateway->hideFromLookup();  // and the next lookup of that document does not find it yet
    $this->gateway->loseNextPaymentAnswer();  // the next payment report arrives, but its answer is lost
    $this->gateway->refusePaymentNext();      // the next payment report is refused at once, as Moj-eRačun answers
    $this->gateway->setStatus('1001', DocumentStatus::DELIVERED, FiscalizationStatus::FISCALIZED);

    // ... send

    expect($this->gateway->sent)->toHaveCount(1)
        ->and(UblSchema::errors($this->gateway->sent[0]))->toBe([]);
});
```

With `ready` false every call is refused as without an account, and `status()`, `reportPayment()` and
`fiscalizeAgain()` know only the documents sent through it or given a standing with `setStatus()`.

It holds an app to what the real intermediaries do:

- a document of a number it holds already for the year (of its `cbc:IssueDate`), through the same intermediary and
  service, is refused with an `EInvoiceRefusedException`, unless the one it holds is `REFUSED`; so an app that sends a
  document again without looking it up first fails its tests as it would fail in production;
- `refuseNext()` refuses only a send and `unavailableNext()` fails only `ping()`, as only those of the real clients throw
  them, so a lookup or a status asked first passes; `failNext()` fails whatever call comes next;
- `hideFromLookup($calls)` keeps the next document whose answer is lost out of that many lookups of it (one by default),
  as an intermediary whose lists do not show a document yet minutes after it took it;
- a standing set with `answersError` answers `fiscalization`, `payment`, their reasons and `paymentReports` as unknown
  (null), as the real clients do;
- for a `REJECTED` standing the `error` set is its `rejectionReason`, unless `setStatus()` is given one apart
  (`rejectionReason:`, the buyer's note), when `error` is its failed fiscalization's `fiscalizationError`, as ePoslovanje
  answers both; for a `REFUSED` standing `error` is the intermediary's reason (its `fiscalizationError` null, as nothing
  was fiscalized), and for any other standing whose fiscalization failed it is that failure's `fiscalizationError`;
- `fiscalizeAgain()` sets a failed or unanswered fiscalization back to waiting, clearing only the fiscalization's reason
  (the buyer's reason for a rejection stays), and leaves a fiscalized one as it is: a fiscalization taken
  stays taken whatever a later request says, as ePoslovanje's standing reads;
- `reportPayment()` takes a report and `status()` tells its answer later (`paymentAnswer`), as ePoslovanje answers;
  `refusePaymentNext($reason)` has the next report refused at once, as Moj-eRačun answers: `reportPayment()` throws a
  `PaymentReportRefusedException` with `HTTP 400: ` and the reason, and the report still counts in `paymentReports`,
  its `payment` failed with the reason in `paymentError`.

The fake also records `payments`, `pings`, the documents asked to be fiscalized again (`refiscalized`), and every call
with the intermediary and the service it stood in for (`calls`), so a test can see a document followed up where it went.
`paymentAnswer` is what `status()` tells of a payment once it is reported (fiscalized unless a test says otherwise), and
`paymentReports` counts the reports made through it, those refused at once (`refusePaymentNext()`) included, unless
`setStatus()` names a number. `businessUnits` holds the units `businessUnits()` lists for an OIB (none by default),
`deleteDocument($electronicId)` stands for a document deleted in the intermediary's web application (no lookup finds
it again, so its number can go again), and `setStatus()` takes `deliveryRetriesUntil` and `accepted` as `status()`
answers them.

`UblSchema::errors($xml)` checks a document against the UBL 2.1 Invoice or CreditNote schema with the Croatian
extension and returns what is wrong, or an empty array.

## What it does not do

- It issues e-invoices; it does not receive them.
- It talks to Moj-eRačun and ePoslovanje. `EInvoiceGateway` leaves room for other intermediaries.
- ePoslovanje refuses a buyer that is not in the tax authority's address registry (AMS) at send, where Moj-eRačun reports
  the invoice to the tax authority itself (`REPORTED`). The package does not report such an invoice through ePoslovanje
  instead (its `/ereporting/reportdocument`, type `IR`): its test server does not check the registry, so the path could
  not be tried. The refusal comes back as the document's; report the invoice another way.
- It writes an invoice outside the scope of VAT only whole, as an issuer outside the VAT system sends one (every line
  `O`); a line outside its scope beside others within it is not supported.

## Schemas

The XML schemas in `resources/ubl` are OASIS's UBL 2.1 and the Croatian Tax Administration's extension, included as
their publishers distribute them. Two of the UBL files, `UBL-CommonExtensionComponents-2.1.xsd` and
`UBL-ExtensionContentDataType-2.1.xsd`, are the Tax Administration's modified copies, which carry its extension, not
OASIS's own. They keep their own terms and are not covered by this package's license; see
[resources/ubl/NOTICE.md](resources/ubl/NOTICE.md).

## License

MIT. See [LICENSE.md](LICENSE.md).
