## [4.1.0-RC1] - 2026-08-20

### 🚀 Features

- *(storage)* Add Azure AD credentials and cross-provider object listing
- *(replay)* Add a cassette-index chain to resolve a bare id to a cassette

### 🚜 Refactor

- *(storage)* Decouple cloud-azure/cloud-s3/cloud-gcs from the framework
## [4.0.0] - 2026-08-11

### 🚀 Features

- *(filesystem)* Read cloud file metadata over HEAD

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 🚜 Refactor

- *(packages)* [**breaking**] Extract the cloud clients into cloud-* packages
- *(storage)* [**breaking**] Give the object stores one contract and one implementation

### 📚 Documentation

- *(api)* Document every public method and class across the framework
