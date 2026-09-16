# Third-Party Notices

Syndicatum's original source code is licensed under `AGPL-3.0-only`. Files or
components obtained from third parties remain governed by their respective
copyright holders' licenses and notices and are not relicensed merely because
they are distributed in this repository.

## Vendored PBB components

The runtime subsets listed in [`VENDORED.md`](VENDORED.md) come from:

- [PBB Helper](https://github.com/jybanez/helpers.pbb.ph), pinned to commit
  `cf52953927f3526965556c4542f8c48218d03aee`; and
- [PBB Realtime](https://github.com/jybanez/realtime.pbb.ph), pinned to commit
  `845c60bd27040f85ed0757c56f972c02b345bca9`.

The current vendored directories do not contain upstream license files. Their
copyright ownership, license terms, compatibility with this distribution, and
required notices must be confirmed before a public V1 release. Until that audit
is complete, do not interpret the repository-level AGPL declaration as a claim
that Syndicatum owns or can relicense those independently copyrighted files.

## Required release action

Before publishing a V1 source or binary distribution:

1. inventory every dependency, font, icon, image, and vendored source file;
2. verify its upstream source, exact version, copyright owner, and license;
3. confirm license compatibility with the intended distribution;
4. retain all required license texts, attribution, and notices; and
5. remove or replace any component whose distribution rights cannot be verified.
