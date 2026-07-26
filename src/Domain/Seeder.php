<?php

declare(strict_types=1);

namespace XReplyAgent\Domain;

use XReplyAgent\Storage\Store;

final class Seeder
{
    /**
     * @return array<string, mixed>
     */
    public static function seed(): array
    {
        $seeded = Store::seedDefaults();
        $demo = Store::seedDemoData();
        $accounts = self::seedDemoAccounts();

        return [
            'defaults' => $seeded,
            'demo' => $demo,
            'accounts' => $accounts,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function seedDemoAccounts(): array
    {
        $accounts = [
            [
                'login' => 'demouser',
                'password' => 'demouser',
                'email' => 'demouser@localhost',
                'role' => 'xra_viewer',
            ],
            [
                'login' => 'demoadmin',
                'password' => 'demoadmin',
                'email' => 'demoadmin@localhost',
                'role' => 'xra_administrator',
            ],
        ];

        $result = [];
        foreach ($accounts as $account) {
            $result[$account['login']] = self::ensureAccount(
                (string) $account['login'],
                (string) $account['password'],
                (string) $account['email'],
                (string) $account['role']
            );
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function ensureAccount(string $login, string $password, string $email, string $role): array
    {
        $user = get_user_by('login', $login);
        if ($user instanceof \WP_User) {
            wp_update_user([
                'ID' => $user->ID,
                'user_pass' => $password,
                'user_email' => $email,
            ]);

            $user->set_role($role);
            return [
                'created' => false,
                'user_id' => (int) $user->ID,
                'role' => $role,
            ];
        }

        $userId = wp_insert_user([
            'user_login' => $login,
            'user_pass' => $password,
            'user_email' => $email,
            'role' => $role,
        ]);

        if (is_wp_error($userId)) {
            return [
                'created' => false,
                'error' => $userId->get_error_message(),
            ];
        }

        $user = get_user_by('id', (int) $userId);
        if ($user instanceof \WP_User) {
            $user->set_role($role);
        }

        return [
            'created' => true,
            'user_id' => (int) $userId,
            'role' => $role,
        ];
    }
}
