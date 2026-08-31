<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Exception;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    // Registrar API (full_name, email, password, confirm_password)
    public function register(Request $request)
    {
        // Validación de los datos de entrada
        $request->validate([
            'full_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'full_name' => $request->full_name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Usuario registrado exitosamente',
        ], 201);
    }

    // Iniciar sesión API (email, password)
    public function login(Request $request)
    {
        // Validación de los datos de entrada
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'status' => false,
                'message' => 'Credenciales inválidas',
            ], 401);
        }

        $user = Auth::user();
        $user->load(['currentLevel', 'achievements.achievement']);
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Inicio de sesión exitoso',
            'token' => $token,
            'user' => $user,
        ]);
    }

    // Solicitar enlace de restablecimiento de contraseña
    public function forgotPassword(Request $request)
    {
        Log::info('=== INICIO FORGOT PASSWORD ===');
        Log::info('Email recibido: '.$request->email);

        $request->validate([
            'email' => 'required|email',
        ]);

        Log::info('Validación pasada correctamente');

        try {
            // Verificar si el usuario existe
            $user = User::where('email', $request->email)->first();
            if (! $user) {
                Log::warning('Usuario no encontrado para email: '.$request->email);

                return response()->json([
                    'status' => false,
                    'message' => 'No se encontró un usuario con ese correo electrónico',
                    'error' => 'users.not_found',
                ], 404);
            }

            Log::info('Usuario encontrado, intentando enviar enlace de reset...');

            $status = Password::sendResetLink(
                $request->only('email')
            );

            Log::info('Status del Password::sendResetLink: '.$status);

            if ($status === Password::RESET_LINK_SENT) {
                Log::info('Enlace de reset enviado exitosamente');

                return response()->json([
                    'status' => true,
                    'message' => 'Enlace de restablecimiento enviado al correo electrónico',
                ], 200);
            }

            Log::error('No se pudo enviar el enlace. Status: '.$status);

            return response()->json([
                'status' => false,
                'message' => 'No se pudo enviar el enlace de restablecimiento',
                'error' => __($status),
                'status_code' => $status,
            ], 400);

        } catch (Exception $e) {
            Log::error('EXCEPCIÓN en forgotPassword: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());

            return response()->json([
                'status' => false,
                'message' => 'Error interno del servidor',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    // Restablecer contraseña
    public function resetPassword(Request $request)
    {
        Log::info('=== INICIO RESET PASSWORD ===');
        Log::info('Email: '.$request->email);

        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        Log::info('Validación pasada correctamente');

        try {
            // Verificar si el token existe en la tabla
            $tokenExists = \DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->first();

            if (! $tokenExists) {
                Log::warning('No se encontró token para el email: '.$request->email);

                return response()->json([
                    'status' => false,
                    'message' => 'Token de restablecimiento inválido o expirado',
                    'error' => 'token.not_found',
                ], 400);
            }

            Log::info('Token encontrado, verificando coincidencia...');

            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function (User $user, string $password) {
                    Log::info('Callback de reset ejecutándose para usuario: '.$user->email);

                    $user->forceFill([
                        'password' => Hash::make($password),
                    ])->setRememberToken(Str::random(60));

                    $user->save();

                    Log::info('Contraseña actualizada correctamente');

                    // Disparar evento de restablecimiento de contraseña
                    event(new PasswordReset($user));

                    // Opcional: Revocar todos los tokens existentes por seguridad
                    $user->tokens()->delete();

                    Log::info('Tokens de usuario revocados');
                }
            );

            Log::info('Status del Password::reset: '.$status);

            if ($status === Password::PASSWORD_RESET) {
                // Eliminar explícitamente el token usado de la tabla
                $deleted = \DB::table('password_reset_tokens')
                    ->where('email', $request->email)
                    ->delete();

                Log::info('Tokens eliminados de la tabla: '.$deleted);

                return response()->json([
                    'status' => true,
                    'message' => 'Contraseña restablecida exitosamente',
                ], 200);
            }

            Log::error('No se pudo restablecer la contraseña. Status: '.$status);

            return response()->json([
                'status' => false,
                'message' => 'No se pudo restablecer la contraseña',
                'error' => __($status),
                'status_code' => $status,
            ], 400);

        } catch (Exception $e) {
            Log::error('EXCEPCIÓN en resetPassword: '.$e->getMessage());
            Log::error('Stack trace: '.$e->getTraceAsString());

            return response()->json([
                'status' => false,
                'message' => 'Error interno del servidor',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    // Perfil API (requiere autenticación)
    public function profile(Request $request)
    {
        $user = $request->user();

        if ($user) {
            $user->load(['currentLevel', 'achievements.achievement']);

            return response()->json([
                'status' => true,
                'data' => $user,
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'Usuario no autenticado o no encontrado',
        ], 401);
    }

    // Cerrar sesión API (requiere autenticación)
    public function logout(Request $request)
    {
        try {
            $user = $request->user();

            if ($user) {
                $user->tokens()->delete();

                return response()->json([
                    'response_code' => 200,
                    'status' => 'success',
                    'message' => 'Successfully logged out',
                ]);
            }

            return response()->json([
                'response_code' => 401,
                'status' => 'error',
                'message' => 'User not authenticated',
            ], 401);
        } catch (\Exception $e) {
            return response()->json([
                'response_code' => 500,
                'status' => 'error',
                'message' => 'An error occurred during logout',
            ], 500);
        }
    }
}
