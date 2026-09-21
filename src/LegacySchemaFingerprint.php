<?php

/** Stable MySQL 8.4 schema-object fingerprint; excludes row counts and AUTO_INCREMENT counters. */
final class LegacySchemaFingerprint
{
    public static function sha256(PDO $pdo)
    {
        $json = json_encode(self::inventory($pdo), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Schema-object inventory could not be serialized.');
        }
        return hash('sha256', $json);
    }

    public static function inventory(PDO $pdo)
    {
        $queries = [
            'tables' => "SELECT table_name, engine, table_collation, row_format
                FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
                ORDER BY table_name",
            'columns' => "SELECT table_name, ordinal_position, column_name, column_type, is_nullable,
                    column_default, extra, character_set_name, collation_name, generation_expression
                FROM information_schema.columns WHERE table_schema = DATABASE()
                ORDER BY table_name, ordinal_position",
            'indexes' => "SELECT table_name, index_name, seq_in_index, column_name, non_unique,
                    index_type, collation, sub_part, expression
                FROM information_schema.statistics WHERE table_schema = DATABASE()
                ORDER BY table_name, index_name, seq_in_index",
            'constraints' => "SELECT table_name, constraint_name, constraint_type
                FROM information_schema.table_constraints WHERE constraint_schema = DATABASE()
                ORDER BY table_name, constraint_name",
            'constraint_columns' => "SELECT table_name, constraint_name, ordinal_position, column_name,
                    referenced_table_name, referenced_column_name, position_in_unique_constraint
                FROM information_schema.key_column_usage WHERE constraint_schema = DATABASE()
                ORDER BY table_name, constraint_name, ordinal_position",
            'references' => "SELECT table_name, constraint_name, referenced_table_name, update_rule, delete_rule
                FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE()
                ORDER BY table_name, constraint_name",
            'checks' => "SELECT tc.table_name, tc.constraint_name, cc.check_clause
                FROM information_schema.table_constraints tc
                JOIN information_schema.check_constraints cc
                  ON cc.constraint_schema = tc.constraint_schema AND cc.constraint_name = tc.constraint_name
                WHERE tc.constraint_schema = DATABASE() AND tc.constraint_type = 'CHECK'
                ORDER BY tc.table_name, tc.constraint_name",
            'triggers' => "SELECT trigger_name, event_object_table, event_manipulation,
                    action_timing, action_statement
                FROM information_schema.triggers WHERE trigger_schema = DATABASE()
                ORDER BY trigger_name",
        ];
        $objects = [];
        foreach ($queries as $name => $sql) {
            $objects[$name] = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }
        return $objects;
    }
}
