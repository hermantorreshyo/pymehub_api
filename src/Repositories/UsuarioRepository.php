<?php
declare(strict_types=1);

namespace PymeHub\Repositories;

use PymeHub\Core\Db;

final class UsuarioRepository
{
    public static function findByEmail(string $email): ?array
    {
        return Db::one(
            "SELECT u.*, i.estado AS i_estado
               FROM usuario u JOIN interlocutor i ON i.id = u.interlocutor_id
              WHERE u.email = ? AND u.eliminado_en IS NULL",
            [$email]
        );
    }

    /** Busca un usuario dentro de un interlocutor (aislamiento SEC-007). */
    public static function findInInterlocutor(int $id, int $interlocutorId): ?array
    {
        return Db::one(
            'SELECT id, interlocutor_id, perfil, nombre, apellidos, email, estado_acceso, ultimo_acceso_en, creado_en
               FROM usuario WHERE id = ? AND interlocutor_id = ? AND eliminado_en IS NULL',
            [$id, $interlocutorId]
        );
    }

    public static function emailExists(string $email): bool
    {
        return Db::value('SELECT 1 FROM usuario WHERE email = ?', [$email]) !== null;
    }

    public static function create(int $interlocutorId, string $tipoInterlocutor, string $perfil, string $nombre, string $apellidos, ?string $email, string $estado = 'SIN_ACCESO', ?string $passwordHash = null): int
    {
        return Db::insert(
            'INSERT INTO usuario (interlocutor_id, tipo_interlocutor, perfil, nombre, apellidos, email, password_hash, estado_acceso)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$interlocutorId, $tipoInterlocutor, $perfil, $nombre, $apellidos, $email, $passwordHash, $estado]
        );
    }

    /** Usuarios de gestión (GERENTE y RRHH) de una empresa. */
    public static function listGestion(int $interlocutorId): array
    {
        return Db::all(
            "SELECT id, perfil, nombre, apellidos, email, estado_acceso, ultimo_acceso_en, creado_en
               FROM usuario
              WHERE interlocutor_id = ? AND perfil IN ('GERENTE','RRHH') AND eliminado_en IS NULL
              ORDER BY perfil, apellidos, nombre",
            [$interlocutorId]
        );
    }
}
