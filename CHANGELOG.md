# [1.1.0](https://github.com/axcelmediacorp/symfony-auth-boilerplate/compare/v1.0.0...v1.1.0) (2026-10-12)


### Bug Fixes

* **admin:** hide Set Password on the viewer's own row and on inactive admins ([#107](https://github.com/axcelmediacorp/symfony-auth-boilerplate/issues/107)) ([68db031](https://github.com/axcelmediacorp/symfony-auth-boilerplate/commit/68db0316fbe58fa7a49e528c95575ae9c5c6d878))
* **test:** AdminPasswordGateArchitectureTest now enforces the one-gate rule ([#103](https://github.com/axcelmediacorp/symfony-auth-boilerplate/issues/103)) ([8f66cc9](https://github.com/axcelmediacorp/symfony-auth-boilerplate/commit/8f66cc9a450cac024afe3f0575fd2dfb1b25dbc7))


### Features

* **admin:** one gate for admin password changes; change-my-password and tech-support set-password (FEATURE-191) ([b58b2ed](https://github.com/axcelmediacorp/symfony-auth-boilerplate/commit/b58b2ed0e2f420cc402b2986e660669aefac18a6))

# 1.0.0 (2026-10-11)


### Bug Fixes

* **release:** make interrupted-update recovery safer and resumable ([0b8a95d](https://github.com/axcelmediacorp/symfony-auth-boilerplate/commit/0b8a95df1ca22d6c5e2a21cd891cd72bffbe80f3))
* **release:** never restore the old DB after a committed update ([5b260ba](https://github.com/axcelmediacorp/symfony-auth-boilerplate/commit/5b260baad34c0443e35ab8553f72913e5d883599))
* **release:** restore on aborted updates and harden the installer ([25f6227](https://github.com/axcelmediacorp/symfony-auth-boilerplate/commit/25f6227a8fa357d5bb2d79f193ff4c26d302c9a0))


### Features

* **release:** automatic releases and a one-line installer/updater ([b9dab47](https://github.com/axcelmediacorp/symfony-auth-boilerplate/commit/b9dab4791e36a818609a20bae2b711efd2d40247))
