This project is abandoned, use [git hooks](https://github.com/Disparity/git-hooks) instead.


[![Latest Stable Version](https://poser.pugx.org/disparity/deployment-bundle/v/stable.svg)](https://packagist.org/packages/disparity/deployment-bundle) [![Total Downloads](https://poser.pugx.org/disparity/deployment-bundle/downloads.svg)](https://packagist.org/packages/disparity/deployment-bundle) [![Latest Unstable Version](https://poser.pugx.org/disparity/deployment-bundle/v/unstable.svg)](https://packagist.org/packages/disparity/deployment-bundle) [![License](https://poser.pugx.org/disparity/deployment-bundle/license.svg)](https://packagist.org/packages/disparity/deployment-bundle)

Symfony 2 Bundle for easy migration to the specified commit / tag / branch execution (and revert) migration doctrine and installing dependencies

## Usage

`app/console disparity:deployment:migrate [--display-sql] [--clean-working-copy] [branch-name | tag | commit]`

## Composer Installation

```json
{
  "require": {
    "disparity/deployment-bundle": ">=0.1"
  }
}
```

Through terminal: `composer require disparity/deployment-bundle:>=0.1`
