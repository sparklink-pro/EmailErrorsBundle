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
  ignored_exception_class: [] 
  graphql: true
```

You can ignore some exceptions by adding the class name in the `ignored_exception_class` array.  
If `graphql` is set to `true`, the bundle will also handle errors from the `GraphQLBundle` and send them.

## Security panel

When the project uses the Symfony security component, error emails start with a **Security** panel showing the authentication state of the request that triggered the error: authenticated or not, user identifier, roles, and whether a session cookie was sent. This makes it possible to diagnose errors caused by expired sessions — typically GraphQL `Cannot query field` errors on fields hidden from anonymous users by a `public: "@=hasRole(...)"` expression, for which a **Probable cause** panel is also added.

The panel is enabled automatically when the `security.token_storage` service exists; nothing to configure.

The **Probable cause** panel only ever appears with `graphql: true`: `Cannot query field` errors are returned in the GraphQL response rather than thrown, so they only reach the mailer through the bundle's GraphQL listener.

## Redaction of sensitive values

Since these emails travel through a mail provider and sit in a shared mailbox, values that would allow whoever reads them to impersonate the user are redacted (only the first 8 characters are kept):

- cookies whose name contains `sess`, `token`, `auth` or `remember` (e.g. `PHPSESSID`, `REMEMBERME`), the configured session cookie, and `cf_clearance`;
- the `Authorization`, `Cookie`, `PHP-Auth-*`, `X-Api-Key`, `X-Auth-Token` and `X-CSRF-Token` headers;
- session keys starting with `_security` (the serialized security token embeds the user object, password hash included).

Request parameters whose key contains `password`, `passwd` or `secret` are replaced by `[redacted]` with no prefix kept, at any depth, in the GET parameters, the POST parameters and a JSON request body (e.g. GraphQL variables of a sign-up mutation). A password written as a literal inside a GraphQL query string, rather than passed as a variable, is not detected.
## Throttling

An error repeated in a loop (a page called again and again, a command run every minute) would send the same email each time. With `throttle` (in seconds, `0` by default = off), the same error — same class, message, file and line — is sent at most once during that time:

```yaml
# config/packages/email_errors.yaml
email_errors:
  throttle: 3600
```

The errors already sent are kept in the `cache.app` cache pool. An email that could not be sent is not throttled: the error is sent again the next time.

## Sending log records (Monolog)

When Monolog is installed, the bundle defines a Monolog handler, `email_errors.monolog_handler`, which sends the records it receives through the same mailer as the exceptions: same template, filters, redaction and throttling. The exception of the record (`['exception' => $e]` in its context) is sent if there is one, otherwise its message, with the stack trace of where it was logged.

It only sends the records from its level, `critical` by default (option `monolog_level`). Use it for one channel, for example the alerts of a synchronisation run by a command, which the exception listener doesn't see:

```yaml
# config/packages/monolog.yaml
monolog:
    channels: [crm]
    handlers:
        crm_alerts:
            type: service
            id: email_errors.monolog_handler
            channels: [crm]
```

Then `$crmLogger->critical('No sync succeeded for 15 minutes')` sends an email. Avoid using it for every channel: Symfony already logs each uncaught exception of a page as critical in the `request` channel, which the exception listener sends too.

When the bundle is disabled (`enabled: false`), the handler still exists but sends nothing, so the Monolog configuration keeps working.
