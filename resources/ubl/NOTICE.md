# Schemas

The XML schemas in this folder are included so that documents can be validated against them, as their publishers
distribute them; this package changes none of them. They are not covered by the package's MIT license; each keeps its
own terms.

- Everything in `maindoc/` and `common/` except the three files below: OASIS Universal Business Language (UBL) 2.1,
  Copyright (c) OASIS Open 2013, from http://docs.oasis-open.org/ubl/os-UBL-2.1/, unchanged. This includes OASIS's
  copies of the ETSI XAdES and W3C XML Signature schemas that come with the UBL 2.1 release.
- `common/HRExtensionAggregateComponents-1.xsd`: the Croatian extension (HRFISK20Data), published by the Croatian
  Tax Administration (Porezna uprava) with the e-invoice specification for fiscalization 2.0.
- `common/UBL-CommonExtensionComponents-2.1.xsd` and `common/UBL-ExtensionContentDataType-2.1.xsd`: OASIS UBL 2.1
  files (Copyright (c) OASIS Open 2013) as the Croatian Tax Administration modified them for its extension and
  distributes them with it, not as OASIS publishes them. In the first, it imports the Croatian extension, comments out
  the include of the second ("OVO JE ZBOG JAXB-a") and defines `ExtensionContentType` itself, as a choice of
  `hrextac:HRFISK20Data` or `sig:UBLDocumentSignatures`; in the second, it comments out OASIS's definition of
  `ExtensionContentType`. Both keep OASIS's copyright notice.
