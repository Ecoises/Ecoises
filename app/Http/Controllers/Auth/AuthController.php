<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;// Asegúrate de que esta importación esté presente
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

        // Opcional: Autenticar al usuario inmediatamente después del registro
        // $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Usuario registrado exitosamente',
            // 'token' => $token // Si lo autenticas inmediatamente
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

   

    // Perfil API (requiere autenticación, no user_id en el request)
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
            // Log::error('Logout Error: ' . $e->getMessage());

            return response()->json([
                'response_code' => 500,
                'status'        => 'error',
                'message'       => 'An error occurred during logout',
            ], 500);
        }
    }

}