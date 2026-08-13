## [4.0.0] - 2026-08-11

### 🚀 Features

- *(rector)* Start the Context-decomposition rule set with rule 1 and its type-resolution foundation
- *(rector)* Add rule 2, and the two guards the framework dry-run proved necessary
- *(rector)* Rule 3's request half, written and then withheld as unsound
- *(rector)* Rule 3's request half, corrected to target RequestState
- *(rector)* Add rule 4, getModel() to an injected ModelLocator
- *(rector)* Add rule 5, Context::getInstance() to an injected ContextRegistry
- *(rector)* Add the residue reporter, unregistered pending a static-call gap
- *(rector)* Rewrite Context::getUser() to an injected user
- *(context)* [**breaking**] Bind the optional components so their absence explains itself

### 🐛 Bug Fixes

- *(rector)* Merge the residue report across Rector workers, and register rule 6
- *(rector)* Stop re-promoting a constructor property the parent owns
- *(rector)* Recognise a Context reached through a nullable getContext()
- *(rector)* Never add a constructor to a class other classes extend
- *(rector)* Stop reporting the methods Context still declares as residue
- *(packages)* [**breaking**] Require the framework by version, not by "*"
- *(rector)* [**breaking**] Close the residue reporter's two blind spots

### 💼 Other

- *(composer)* Alias dev-main to 4.0.x-dev across the monorepo

### 📚 Documentation

- *(rector)* Correct the rule-set config's installation and coverage notes
- *(api)* Document every public method and class across the framework

### 🧪 Testing

- *(rector)* Name the sites the rules skip, and cover the reporter itself

### ⚙️ Miscellaneous Tasks

- *(rector)* Register the package for the subtree split, and stop implying it is published
