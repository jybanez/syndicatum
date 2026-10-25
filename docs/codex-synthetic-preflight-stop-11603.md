# Immutable isolated-test preflight STOP #11603

Owner event #11603 approved isolated request #11601 at source
`a191b3f2cfaf409ff389727239a84943f584debb`. Read-only admission stopped before
any harness invocation, native API or synthetic-tree creation. This one-time
approval is exhausted; it cannot authorize a later retry or changed source.

The committed packet was 1876 bytes, SHA256
`3c3875f57202d18a762a3a72c59597be34df57503fe87a7f9dfa7154d3add056`.
Windows working checkout was 1922 bytes with46 CRLF pairs, SHA256
`31245bd7de2bc5089a64040bd9e046a34908f1cacad49b8e53746a16efd65994`.
Read-only comparison established that replacing CRLF with LF gives the exact
approved blob. No normalization or changed pin was used to admit execution.
The two script LF rules did not include the JSON review packet. Root remained
absent; source/runtime/live state was not mutated by the test. Timeline #11604
records the STOP and its exhausted authority.

Subsequent SOURCE-ONLY correction pins JSON checkout to LF and runs the existing
packet-byte regression under Windows CI. That is new source, not a refresh of
this attempt or authority. Committed packet/harness/backend bytes and their old
review digests remain unchanged. Any future test requires fresh exact-source
review, CI success and a new explicit owner approval. No cleanup, renamed test
root, retry or live operation follows from this document.
