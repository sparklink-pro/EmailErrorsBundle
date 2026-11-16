<?php

declare(strict_types=1);

namespace Sparklink\EmailErrorsBundle\Tests\ExceptionMailer;

use PHPUnit\Framework\TestCase;
use Sparklink\EmailErrorsBundle\ExceptionMailer\ExceptionMailer;
use Sparklink\EmailErrorsBundle\Twig\DataPanel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;

final class ExceptionMailerTest extends TestCase
{
    private TokenStorage $tokenStorage;

    protected function setUp(): void
    {
        $this->tokenStorage = new TokenStorage();
    }

    public function testSecurityPanelForAnonymousUser(): void
    {
        $panels = $this->createMailer()->panels(new Request());

        $security = $this->findPanel($panels, 'Security');

        $this->assertNotNull($security);
        $this->assertSame([
            'authenticated' => 'no',
            'user' => '(anonymous)',
            'roles' => '(none)',
            'session cookie sent' => 'no',
        ], $security->getData());
    }

    public function testSecurityPanelForAuthenticatedUser(): void
    {
        $this->authenticate();

        $panels = $this->createMailer()->panels(new Request());

        $security = $this->findPanel($panels, 'Security');

        $this->assertNotNull($security);
        $this->assertSame('yes', $security->getData()['authenticated']);
        $this->assertSame('john@example.com', $security->getData()['user']);
        $this->assertStringContainsString('ROLE_MEMBER', $security->getData()['roles']);
    }

    public function testNoSecurityPanelWithoutTokenStorage(): void
    {
        $panels = $this->createMailer(withTokenStorage: false)->panels(new Request());

        $this->assertNull($this->findPanel($panels, 'Security'));
    }

    public function testProbableCauseWhenAnonymousUserQueriesHiddenField(): void
    {
        $exception = new \Exception('Cannot query field "RequestOfferPrice" on type "Mutation".');

        $panels = $this->createMailer()->panels(new Request(), $exception);

        $this->assertStringContainsString('not authenticated', $this->findPanel($panels, 'Probable cause')?->getData());
    }

    public function testNoProbableCauseWhenAuthenticated(): void
    {
        $this->authenticate();

        $exception = new \Exception('Cannot query field "RequestOfferPrice" on type "Mutation".');

        $panels = $this->createMailer()->panels(new Request(), $exception);

        $this->assertNull($this->findPanel($panels, 'Probable cause'));
    }

    public function testNoProbableCauseForOtherErrors(): void
    {
        $panels = $this->createMailer()->panels(new Request(), new \Exception('Some other error'));

        $this->assertNull($this->findPanel($panels, 'Probable cause'));
    }

    public function testSensitiveCookiesAreRedacted(): void
    {
        $request = new Request(cookies: [
            'PHPSESSID' => 'abcdefgh-secret-session-id',
            'REMEMBERME' => 'remember-me-token-value',
            'cf_clearance' => 'clearance-value-from-cloudflare',
            '_ga' => 'GA1.2.1568311084.1760085284',
        ]);

        $panels = $this->createMailer()->panels($request);
        $cookies = $this->findPanel($panels, 'Cookies')->getData();

        $this->assertSame('abcdefgh…[redacted]', $cookies['PHPSESSID']);
        $this->assertSame('remember…[redacted]', $cookies['REMEMBERME']);
        $this->assertSame('clearanc…[redacted]', $cookies['cf_clearance']);
        $this->assertSame('GA1.2.1568311084.1760085284', $cookies['_ga']);
    }

    public function testConfiguredSessionCookieIsRedacted(): void
    {
        $request = new Request(cookies: ['MOCKSESSID' => 'mock-session-id-value']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $panels = $this->createMailer()->panels($request);
        $cookies = $this->findPanel($panels, 'Cookies')->getData();

        $this->assertSame('mock-ses…[redacted]', $cookies['MOCKSESSID']);
    }

    public function testSensitiveHeadersAreRedacted(): void
    {
        $request = new Request(server: [
            'HTTP_AUTHORIZATION' => 'Bearer very-secret-api-token',
            'HTTP_COOKIE' => 'PHPSESSID=abcdefgh-secret-session-id',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ]);

        $panels = $this->createMailer()->panels($request);
        $headers = $this->findPanel($panels, 'Headers')->getData();

        $this->assertSame(['Bearer v…[redacted]'], $headers['authorization']);
        $this->assertSame(['PHPSESSI…[redacted]'], $headers['cookie']);
        $this->assertSame(['Mozilla/5.0'], $headers['user-agent']);
    }

    public function testValidationErrorsPanelFromExceptionChain(): void
    {
        $inner = new class('Invalid payload') extends \Exception {
            public array $violations = [];

            public function getViolations(): array
            {
                return $this->violations;
            }
        };
        $inner->violations[] = new class {
            public function getPropertyPath(): string
            {
                return 'content';
            }

            public function getMessage(): string
            {
                return 'Inquiry-quota has been reached today.';
            }

            public function getCode(): ?string
            {
                return '9671f975-2611-4d44-900c-fb7fa2f86480';
            }
        };

        $outer = new \Exception('GraphQL error', 0, $inner);

        $panels = $this->createMailer()->panels(new Request(), $outer);
        $violations = $this->findPanel($panels, 'Validation errors');

        $this->assertNotNull($violations);
        $this->assertSame(['content: Inquiry-quota has been reached today. (code 9671f975-2611-4d44-900c-fb7fa2f86480)'], $violations->getData());
    }

    public function testNoValidationErrorsPanelWithoutViolations(): void
    {
        $panels = $this->createMailer()->panels(new Request(), new \Exception('Some error'));

        $this->assertNull($this->findPanel($panels, 'Validation errors'));
    }

    public function testSerializedSecurityTokenInSessionIsRedacted(): void
    {
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $session->set('_security_main', 'O:74:"Symfony...Token":3:{serialized token with password hash}');
        $session->set('some_app_key', 'kept as is');
        $request->setSession($session);

        $panels = $this->createMailer()->panels($request);
        $sessionData = $this->findPanel($panels, 'Session')->getData();

        $this->assertSame('O:74:"Sy…[redacted]', $sessionData['_security_main']);
        $this->assertSame('kept as is', $sessionData['some_app_key']);
    }

    public function testShortSensitiveValuesAreFullyRedacted(): void
    {
        $request = new Request(cookies: ['PHPSESSID' => 'short']);

        $panels = $this->createMailer()->panels($request);

        $this->assertSame('[redacted]', $this->findPanel($panels, 'Cookies')->getData()['PHPSESSID']);
    }

    public function testPasswordInJsonBodyIsFullyRedacted(): void
    {
        // A GraphQL batch sign-up mutation.
        $body = json_encode([[
            'variables' => ['payload' => ['email' => 'john@example.com', 'password' => 'not-a-real-password']],
            'query' => 'mutation ($payload: UserSubscribePayload!) { UserSubscribe(payload: $payload) { uuid } }',
        ]]);

        $panels = $this->createMailer()->panels(new Request(content: $body));
        $content = $this->findPanel($panels, 'Request content')->getData();

        $this->assertSame('[redacted]', $content[0]['variables']['payload']['password']);
        $this->assertSame('john@example.com', $content[0]['variables']['payload']['email']);
        $this->assertStringContainsString('UserSubscribe', $content[0]['query']);
    }

    public function testPasswordParametersAreFullyRedacted(): void
    {
        $request = new Request(
            query: ['plainPassword' => 'in-the-url', 'page' => '2'],
            request: [
                '_username' => 'john@example.com',
                '_password' => 'not-a-real-password',
                'user' => ['password' => ['first' => 'secret-1', 'second' => 'secret-1']],
                'client_secret' => 'oauth-client-secret',
            ],
        );

        $panels = $this->createMailer()->panels($request);
        $get = $this->findPanel($panels, 'GET Parameters')->getData();
        $post = $this->findPanel($panels, 'POST Parameters')->getData();

        $this->assertSame(['plainPassword' => '[redacted]', 'page' => '2'], $get);
        $this->assertSame([
            '_username' => 'john@example.com',
            '_password' => '[redacted]',
            'user' => ['password' => '[redacted]'],
            'client_secret' => '[redacted]',
        ], $post);
    }

    public function testPasswordInJsonStringParameterIsFullyRedacted(): void
    {
        // A GraphQL multipart request (file upload): query and variables travel as a JSON string in "operations".
        $operations = json_encode([
            'query' => 'mutation ($payload: UserSubscribePayload!) { UserSubscribe(payload: $payload) { uuid } }',
            'variables' => ['payload' => ['email' => 'john@example.com', 'password' => 'not-a-real-password', 'avatar' => null]],
        ]);
        $map = json_encode(['0' => ['variables.payload.avatar']]);

        $panels = $this->createMailer()->panels(new Request(request: ['operations' => $operations, 'map' => $map]));
        $post = $this->findPanel($panels, 'POST Parameters')->getData();

        $this->assertStringNotContainsString('not-a-real-password', $post['operations']);
        $this->assertSame('[redacted]', json_decode($post['operations'], true)['variables']['payload']['password']);
        $this->assertSame('john@example.com', json_decode($post['operations'], true)['variables']['payload']['email']);
        $this->assertSame($map, $post['map'], 'A JSON string without sensitive key is kept as is');
    }

    public function testSessionCookieIsRedactedWhenTheSessionWasNotInitialized(): void
    {
        // A session cookie whose name matches no pattern, on a request where the application never touched the session
        $request = new Request(cookies: ['sid' => 'secret-session-id-value']);
        $request->setSessionFactory(fn () => new Session(new MockArraySessionStorage('sid')));

        $panels = $this->createMailer(withTokenStorage: false)->panels($request);

        $this->assertSame('secret-s…[redacted]', $this->findPanel($panels, 'Cookies')->getData()['sid']);
        $this->assertNull($this->findPanel($panels, 'Session'), 'The session is not started just to show it');
        $this->assertFalse($request->getSession()->isStarted());
    }

    public function testExceptionWithUnexpectedGetViolationsSignatureIsIgnored(): void
    {
        $exception = new class('Invalid payload') extends \Exception {
            public function getViolations(string $group): array
            {
                return [];
            }
        };

        $panels = $this->createMailer()->panels(new Request(), $exception);

        $this->assertNull($this->findPanel($panels, 'Validation errors'));
    }

    public function testNonJsonBodyStillGetsAPanel(): void
    {
        $panels = $this->createMailer()->panels(new Request(content: 'not json'));

        $this->assertNotNull($this->findPanel($panels, 'Request content'));
    }

    private function authenticate(): void
    {
        $user = new InMemoryUser('john@example.com', null, ['ROLE_MEMBER']);
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    /**
     * Exposes the panels of the email: the error's own panels (protected getExceptionDataPanels()) followed by the
     * request ones (protected getRequestDataPanels()).
     */
    private function createMailer(bool $withTokenStorage = true): object
    {
        return new class($this->createStub(MailerInterface::class), $this->createStub(Environment::class), null, 'from@example.com', 'to@example.com', 'subject', [], [], [], $withTokenStorage ? $this->tokenStorage : null) extends ExceptionMailer {
            public function panels(Request $request, ?\Throwable $exception = null): array
            {
                return [
                    ...$this->getExceptionDataPanels($exception ?? new \Exception(), $request),
                    ...$this->getRequestDataPanels($request),
                ];
            }
        };
    }

    private function findPanel(array $panels, string $title): ?DataPanel
    {
        foreach ($panels as $panel) {
            if ($panel->getTitle() === $title) {
                return $panel;
            }
        }

        return null;
    }
}
