<?php

namespace Tests\Feature;

use App\Models\LiveClass;
use App\Models\User;
use App\Support\LiveClasses\JitsiTokenService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Tests\TestCase;

/**
 * JitsiTokenService is the actual fix for "waiting for a moderator": it
 * signs the token that tells Jitsi who the host is. These tests don't touch
 * the database — LiveClass/User are built in-memory (never saved) since the
 * service only reads plain attributes off them.
 */
class JitsiTokenServiceTest extends TestCase
{
    /**
     * A throwaway 2048-bit RSA keypair, generated once via the `openssl`
     * CLI purely for this test to sign/verify against — not read from
     * anywhere real. Hardcoded rather than generated at runtime with
     * openssl_pkey_new() because that PHP function silently fails
     * ("Cannot get key from parameter 1") on environments without a
     * properly configured openssl.cnf, which several Windows PHP builds
     * lack; the openssl CLI itself works fine there, so the keypair below
     * was produced with `openssl genrsa` / `openssl rsa -pubout` once and
     * committed as fixed test data instead.
     */
    private const TEST_PRIVATE_KEY_PEM = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDEmYhjTUrnDylu
eC4P4qFJgZzaZtxEjof0uS7fuvUhPhSe+pVN+PrdJzKAm0JFzgzxQkavpEbCO4pq
Il0vNs4samLQeAhHL8HJxNpE0YxjSE1tSJ7aDp50L0OCfUuvxDDapmwz5J0olV9l
GZF4CA8MkdspxTc5+g7h7vx4I4rbjMDJJOtzN9ShGURUeFvB9E+HCNDmE4tsnVJf
UaWQGKH5/g/QXdy7fB3/6YDNRerkD8Y3slcfksjjFkqSE5s0JjF9/0ARUoiQZA+2
R0l9ibJI17nZFfXmwmAfi7YPr22PulMAgdIK1anTzWVTwKFwToE+7rOFGgdTt9kO
hArW1/TTAgMBAAECggEAHclrTixUSGzWKKwhwntiV3pBUx/ZiLOVqsDx9qjjYf1/
b1TlETLDg4VgU/byFBRzhow1nIAFzoDp6Em7l580nqshvJcrHeoA8PqX3WxoWlSG
kqO5ma00WcMddeRY9tEBW+dyJmyhWRhEo1M75JVH/m4M6Ce+xdR6s8WbT/KGo9vL
t2fOFR8SZJcknVkSpJlS6j64Acnv1dJAz8iKncrteq8g7hE8Ed2iODKbQN0NUVXU
S+bIKL1bU8YqvShX4P0HE/X5ToKEsPFohnkRl5Oe44fTd07WpvJjrM3+6zgbwVBU
ujn6sJ9cwj8l4JvtGAcKZefmA46XgqnhrDn5EbRqJQKBgQD4PJ5/wGO2nkqJWtsb
1vaJ6H3oBd9GtNBHJpzFmHeD0yobhSLsrl/VPJE1Jm3uqsQDJHYx8fgYToJF/WbC
DfixJ8S6JfqxdAfUN0uiSymBuon0pFh8XR6qOp0sK0HMthBI3t8/gKIClFJLXelL
KM38GjBV8uVCNwlmU2VVivTyZQKBgQDKv4IJwWrFyBax4yCyvtdEO1S5CZxzBzt2
tGlFvr2jZt33sSd4DCNvvI5PP8Kla2YlcILm1lhxGjE8F+pFuHCpPWmOF7E4cBwZ
4TI7PqsMQ58Svb/cUChx3TSsEsh74ZcC9mtjguQr0+9FVN1qkhxpU44+9NgsTAUA
c1OunUe61wKBgQDsRXZvDgVaiitTtSCbzOz3skw0jXJI8EzVjBDnmgkXdeO8Mepd
8FLSuUVUm3FDG1JZ01iUjUSgjgk86MXnqjitFbcPnpqQUGorOT5KCNRG3+/y5II8
TL2lNQjoQ3vrhtbVJRzEaoDJh7cUeRxWJ053x8194SUI8y2FMZdf5lTiaQKBgF96
pnOPqYewvbdSHeDvbJLOWgy10P5+pms1dOoKdGYSGG//9nVbSgjhxCbgAPtDw5vY
C11IDehdjFgfbw0cWbnpmN74m1XXvfQWEEDEN2sUIUKDJ2pKOXG92YM7aTyltScJ
AvOe/XMCYIjG+WqpmkWjurY9OdxKhIR8yyjNGq2LAoGAL2NFQg/DHuCf2a+k5yg5
1sd0g2969bt5GHuO3Bv2fU+RoYxVtCGxsVQpPoXUsL/XqxfPC3OQGegmRlH/E6pu
QVlCd/Eoc2f2PpykLGdbZQlZiQT+wxpAYP/IReydklfSZNzXhMjchqEClRJdE4lz
HYvSmt5+O+RKy3BH8Ff0Cg0=
-----END PRIVATE KEY-----
PEM;

    private const TEST_PUBLIC_KEY_PEM = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAxJmIY01K5w8pbnguD+Kh
SYGc2mbcRI6H9Lku37r1IT4UnvqVTfj63ScygJtCRc4M8UJGr6RGwjuKaiJdLzbO
LGpi0HgIRy/BycTaRNGMY0hNbUie2g6edC9Dgn1Lr8Qw2qZsM+SdKJVfZRmReAgP
DJHbKcU3OfoO4e78eCOK24zAySTrczfUoRlEVHhbwfRPhwjQ5hOLbJ1SX1GlkBih
+f4P0F3cu3wd/+mAzUXq5A/GN7JXH5LI4xZKkhObNCYxff9AEVKIkGQPtkdJfYmy
SNe52RX15sJgH4u2D69tj7pTAIHSCtWp081lU8ChcE6BPu6zhRoHU7fZDoQK1tf0
0wIDAQAB
-----END PUBLIC KEY-----
PEM;

    protected function tearDown(): void
    {
        config([
            'services.jitsi.algorithm' => 'RS256',
            'services.jitsi.app_id' => '',
            'services.jitsi.kid' => '',
            'services.jitsi.private_key' => '',
            'services.jitsi.app_secret' => '',
        ]);

        parent::tearDown();
    }

    public function test_it_reports_not_configured_and_returns_no_token_when_env_is_empty(): void
    {
        config([
            'services.jitsi.app_id' => '',
            'services.jitsi.app_secret' => '',
            'services.jitsi.private_key' => '',
        ]);

        $this->assertFalse(JitsiTokenService::isConfigured());

        $liveClass = new LiveClass(['meeting_url' => 'https://meet.jit.si/some-room-abc123']);
        $user = new User(['id' => 1, 'name' => 'A Teacher', 'email' => 'teacher@example.test']);

        $this->assertNull(JitsiTokenService::generate($liveClass, $user, true));
    }

    public function test_hs256_token_marks_the_host_as_moderator_and_others_as_not(): void
    {
        config([
            'services.jitsi.algorithm' => 'HS256',
            'services.jitsi.app_id' => 'my_app_id',
            'services.jitsi.app_secret' => str_repeat('s', 32),
        ]);

        $liveClass = new LiveClass(['meeting_url' => 'https://meet.example.test/my-class-room-xyz']);
        $teacher = new User(['id' => 5, 'name' => 'Host Teacher', 'email' => 'host@example.test']);
        $student = new User(['id' => 9, 'name' => 'A Student', 'email' => 'student@example.test']);

        $this->assertTrue(JitsiTokenService::isConfigured());

        $hostToken = JitsiTokenService::generate($liveClass, $teacher, true);
        $studentToken = JitsiTokenService::generate($liveClass, $student, false);

        $this->assertNotNull($hostToken);
        $this->assertNotNull($studentToken);

        $hostPayload = (array) JWT::decode($hostToken, new Key(str_repeat('s', 32), 'HS256'));
        $studentPayload = (array) JWT::decode($studentToken, new Key(str_repeat('s', 32), 'HS256'));

        $this->assertTrue($hostPayload['context']->user->moderator);
        $this->assertFalse($studentPayload['context']->user->moderator);
        // The room claim must be just the trailing slug, not a full URL —
        // Jitsi matches this against the room being joined.
        $this->assertSame('my-class-room-xyz', $hostPayload['room']);
        $this->assertSame('my_app_id', $hostPayload['iss']);
        $this->assertSame('jitsi', $hostPayload['aud']);
        $this->assertSame('*', $hostPayload['sub']);
        $this->assertSame('Host Teacher', $hostPayload['context']->user->name);
        $this->assertSame('host@example.test', $hostPayload['context']->user->email);
        $this->assertSame(14410, $hostPayload['exp'] - $hostPayload['nbf']);
        $this->assertTrue($hostPayload['context']->features->recording);
        $this->assertFalse($studentPayload['context']->features->recording);
    }

    public function test_rs256_token_for_jaas_uses_the_app_id_as_subject_and_kid_header(): void
    {
        config([
            'services.jitsi.algorithm' => 'RS256',
            'services.jitsi.app_id' => 'vpaas-magic-cookie-abc123',
            'services.jitsi.kid' => 'vpaas-magic-cookie-abc123/my-key-id',
            'services.jitsi.private_key' => self::TEST_PRIVATE_KEY_PEM,
        ]);

        $this->assertTrue(JitsiTokenService::isConfigured());

        // JaaS-shaped URL: {base}/{appId}/{room} — the app id prefix must
        // NOT leak into the `room` claim itself.
        $liveClass = new LiveClass(['meeting_url' => 'https://8x8.vc/vpaas-magic-cookie-abc123/some-room-slug']);
        $host = new User(['id' => 1, 'name' => 'Host', 'email' => 'host@example.test']);

        $token = JitsiTokenService::generate($liveClass, $host, true);
        $this->assertNotNull($token);

        $payload = (array) JWT::decode($token, new Key(self::TEST_PUBLIC_KEY_PEM, 'RS256'));

        $this->assertSame('some-room-slug', $payload['room']);
        $this->assertSame('vpaas-magic-cookie-abc123', $payload['sub']);
        $this->assertSame('vpaas-magic-cookie-abc123', $payload['iss']);
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $token)[0]), true);
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('vpaas-magic-cookie-abc123/my-key-id', $header['kid']);
    }

    public function test_undersized_hs256_credentials_fail_safely(): void
    {
        config(['services.jitsi.algorithm' => 'HS256', 'services.jitsi.app_id' => 'test-app',
            'services.jitsi.app_secret' => str_repeat('s', 31)]);
        $this->assertFalse(JitsiTokenService::isConfigured());
        $this->assertNull(JitsiTokenService::generate(
            new LiveClass(['meeting_url' => 'https://meet.example.test/room']), new User(), true
        ));
    }

    public function test_malformed_rs256_credentials_fail_safely(): void
    {
        config(['services.jitsi.algorithm' => 'RS256', 'services.jitsi.app_id' => 'test-app',
            'services.jitsi.kid' => 'test-key', 'services.jitsi.private_key' => 'not a private key']);
        $this->assertFalse(JitsiTokenService::isConfigured());
        $this->assertNull(JitsiTokenService::generate(
            new LiveClass(['meeting_url' => 'https://meet.example.test/room']), new User(), true
        ));
    }

    public function test_undersized_rsa_credentials_fail_safely(): void
    {
        // An explicit temporary OpenSSL configuration also works on Windows.
        $configuration = tempnam(sys_get_temp_dir(), 'jitsi-test-');
        try {
            file_put_contents($configuration, "[req]\ndistinguished_name = dn\n[dn]\n");
            $key = openssl_pkey_new(['private_key_bits' => 1024,
                'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $configuration]);
            $this->assertNotFalse($key);
            $this->assertTrue(openssl_pkey_export($key, $pem, null, ['config' => $configuration]));
            config(['services.jitsi.algorithm' => 'RS256', 'services.jitsi.app_id' => 'test-app',
                'services.jitsi.kid' => 'test-key', 'services.jitsi.private_key' => $pem]);
            $this->assertFalse(JitsiTokenService::isConfigured());
            $this->assertNull(JitsiTokenService::generate(
                new LiveClass(['meeting_url' => 'https://meet.example.test/room']), new User(), true
            ));
        } finally {
            unlink($configuration);
        }
    }

    public function test_it_returns_null_when_the_class_has_no_usable_meeting_url(): void
    {
        config([
            'services.jitsi.algorithm' => 'HS256',
            'services.jitsi.app_id' => 'my_app_id',
            'services.jitsi.app_secret' => str_repeat('s', 32),
        ]);

        $liveClass = new LiveClass(['meeting_url' => null]);
        $user = new User(['id' => 1, 'name' => 'Someone', 'email' => 'someone@example.test']);

        $this->assertNull(JitsiTokenService::generate($liveClass, $user, true));
    }
}
