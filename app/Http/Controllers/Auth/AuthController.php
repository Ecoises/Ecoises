<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\PasswordReset;
use Exception;
    
class AuthController extends Controller
{
    // Registrar API (full_name, email, password, confirm_password)
    public function register(Request $request){
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
    public function login(Request $request){
        // Validación de los datos de entrada
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($request->only("email", "password"))) {
            return response()->json([
                "status" => false,
                "message" => "Credenciales inválidas"
            ], 401);
        }

        $user = Auth::user();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Inicio de sesión exitoso',
            'token' => $token,
            'user' => $user 
        ]);
    }

    // Solicitar enlace de restablecimiento de contraseña
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        try {
            $status = Password::sendResetLink(
                $request->only('email')
            );

            if ($status === Password::RESET_LINK_SENT) {
                return response()->json([
                    'status' => true,
                    'message' => 'Enlace de restablecimiento enviado al correo electrónico'
                ], 200);
            }

            return response()->json([
                'status' => false,
                'message' => 'No se pudo enviar el enlace de restablecimiento',
                'error' => __($status)
            ], 400);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error interno del servidor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Restablecer contraseña
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        try {
            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function (User $user, string $password) {
                    $user->forceFill([
                        'password' => Hash::make($password)
                    ])->setRememberToken(Str::random(60));

                    $user->save();

                    // Disparar evento de restablecimiento de contraseña
                    event(new PasswordReset($user));
                    
                    // Opcional: Revocar todos los tokens existentes por seguridad
                    $user->tokens()->delete();
                }
            );

            if ($status === Password::PASSWORD_RESET) {
                return response()->json([
                    'status' => true,
                    'message' => 'Contraseña restablecida exitosamente'
                ], 200);
            }

            return response()->json([
                'status' => false,
                'message' => 'No se pudo restablecer la contraseña',
                'error' => __($status)
            ], 400);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error interno del servidor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Perfil API (requiere autenticación)
    public function profile(Request $request){
        $user = $request->user();
        
        if ($user) {
            return response()->json([
                'status' => true, 
                'data' => $user
            ]);
        }
        
        return response()->json([
            'status' => false,
            'message' => 'Usuario no autenticado o no encontrado',
        ], 401); 
    }

    // Cerrar sesión API (requiere autenticación)
    public function logout(Request $request){
       try {
            $user = $request->user();

            if ($user) {
                $user->tokens()->delete();

                return response()->json([
                    'response_code' => 200,
                    'status'        => 'success',
                    'message'       => 'Successfully logged out',
                ]);
            }

            return response()->json([
                'response_code' => 401,
                'status'        => 'error',
                'message'       => 'User not authenticated',
            ], 401);
        } catch (\Exception $e) {
            return response()->json([
                'response_code' => 500,
                'status'        => 'error',
                'message'       => 'An error occurred during logout',
            ], 500);
        }
    }
}