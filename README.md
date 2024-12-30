# EmailErrorsBundle

[![Latest Stable Version](https://poser.pugx.org/sparklink/email-errors-bundle/v)](//packagist.org/packages/sparklink/email-errors-bundle)
[![Total Downloads](https://poser.pugx.org/sparklink/email-errors-bundle/downloads)](//packagist.org/packages/sparklink/email-errors-bundle)
[![Latest Unstable Version](https://poser.pugx.org/sparklink/email-errors-bundle/v/unstable)](//packagist.org/packages/sparklink/email-errors-bundle)
[![License](https://poser.pugx.org/sparklink/email-errors-bundle/license)](//packagist.org/packages/sparklink/email-errors-bundle)

This Symfony bundle provides a way to send email notifications when an error occurs in your application.

![EmailErrorsBundle](./docs/screeenshot/email_errors.png)
![EmailErrorsBundle](./docs/screeenshot/email_errors_2.png)

## Download the Bundle

Open a command console, enter your project directory and execute the following command to download the latest stable version of this bundle:

```bash
composer require sparklink/email-errors-bundle
```

## Symfony Flex Installation

### Accept the contrib recipes installation from Symfony Flex

```bash
-  WARNING  sparklink/email-errors-bundle (1.0.0): From github.com/symfony/recipes-contrib
    The recipe for this package comes from the "contrib" repository, which is open to community contributions.
    Do you want to execute this recipe?
    [y] Yes
    [n] No
    [a] Yes for all packages, only for the current installation session
    [p] Yes permanently, never ask again for this project
    (defaults to n): 
```

## Manual Installation

### Enable the Bundle

Then, enable the bundle by adding it to the list of registered bundles in the `config/bundles.php` file of your project:

```php
// config/bundles.php

return [
    // ...
    Sparklink\EmailErrorsBundle\EmailErrorsBundle::class => ['all' => true],
];
```

### Configure the Bundle

Create a new file `config/packages/email_errors.yaml` and add the following configuration:

```yaml
# config/packages/email_errors.yaml
email_errors:
  enabled: "%kernel.debug%"           
  from: "%env(resolve:MAILER_ERRORS_FROM)%"
  to: "%env(resolve:MAILER_ERRORS_TO)%"
  ignored_exception_classes: [] 
  graphql: true
```

You can ignore some exceptions by adding the class name in the `ignored_exception_classes` array.  
If `graphql` is set to `true`, the bundle will also handle errors from the `GraphQLBundle` and send them. 
