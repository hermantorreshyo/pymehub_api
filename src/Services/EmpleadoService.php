<?php
declare(strict_types=1);

namespace PymeHub\Services;

use PymeHub\Core\ApiException;
use PymeHub\Core\Db;
use PymeHub\Repositories\EmpleadoRepository;
use PymeHub\Repositories\EquipoRepository;
use PymeHub\Repositories\UsuarioRepository;

/**
 * Reglas de negocio comunes a altas, cambios e importación de empleados.
 */
final class EmpleadoService
{
    /**
     * PH-TENANT-002: las altas no pueden superar limite_empleados de la
     * suscripción vigente. Dentro de una transacción, FOR UPDATE serializa
     * las altas concurrentes de la misma empresa.
     */
    public static function comprobarLimite(int $interlocutorId, int $nuevas): void
    {
        if ($nuevas <= 0) {
            return;
        }
        $limite = Db::value("SELECT limite_empleados FROM suscripcion WHERE interlocutor_id = ? AND estado = 'VIGENTE' FOR UPDATE", [$interlocutorId]);
        $activos = EmpleadoRepository::contarActivos($interlocutorId);
        if ($limite === null || $activos + $nuevas > (int) $limite) {
            throw new ApiException(
                'PH-TENANT-002',
                'Se ha alcanzado el límite de empleados del plan contratado',
                409,
                ['limite_empleados' => (int) $limite, 'empleados_activos' => $activos, 'altas_solicitadas' => $nuevas]
            );
        }
    }

    /** LEG-016: sin contrato de encargado del tratamiento no se invita a empleados (PH-TENANT-003). */
    public static function comprobarContratoEncargado(int $interlocutorId): void
    {
        if (Db::value('SELECT fecha_contrato_encargado FROM interlocutor WHERE id = ?', [$interlocutorId]) === null) {
            throw new ApiException(
                'PH-TENANT-003',
                'Falta registrar el contrato de encargado del tratamiento de la empresa. Contacta con Pyme Hub para invitar a empleados.',
                403
            );
        }
    }

    /** El equipo debe ser un equipo vigente de la misma empresa. */
    public static function validarEquipo(?int $equipoId, int $interlocutorId): void
    {
        if ($equipoId !== null && EquipoRepository::find($equipoId, $interlocutorId) === null) {
            throw ApiException::validation(['equipo_id' => 'Equipo no encontrado']);
        }
    }

    /** El email es único en toda la plataforma (uq_usuario_email). */
    public static function validarEmailLibre(?string $email, ?int $usuarioId = null): void
    {
        if ($email === null) {
            return;
        }
        $owner = UsuarioRepository::emailOwner($email);
        if ($owner !== null && $owner !== $usuarioId) {
            throw new ApiException('PH-VAL-001', 'Ese email ya tiene una cuenta', 409, ['email' => 'Duplicado']);
        }
    }

    /** El código interno es único dentro de la empresa (uq_ficha_codigo). */
    public static function validarCodigoLibre(?string $codigo, int $interlocutorId, ?int $usuarioId = null): void
    {
        if ($codigo === null) {
            return;
        }
        $owner = EmpleadoRepository::idPorCodigo($interlocutorId, $codigo);
        if ($owner !== null && $owner !== $usuarioId) {
            throw new ApiException('PH-VAL-001', 'Ese código interno ya está asignado', 409, ['codigo_interno' => 'Duplicado']);
        }
    }

    /** El email de acceso solo se cambia mientras el empleado no tiene la cuenta activa. */
    public static function emailEditable(string $estadoAcceso): bool
    {
        return in_array($estadoAcceso, ['SIN_ACCESO', 'INVITADO'], true);
    }
}
