<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Google\Client;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class GoogleController extends Controller
{
    /**
     * Handle Google ID Token for authentication.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function authenticate(Request $request)
    {
        // Valida que el id_token esté presente en la solicitud
        $request->validate([
            'id_token' => 'required|string',
        ]);

        $googleIdToken = $request->input('id_token');

        try {
            // Inicializa el cliente de Google con tu ID de Cliente
            // Asegúrate de que GOOGLE_CLIENT_ID esté en tu archivo .env de Laravel
            $client = new Client(['client_id' => env('GOOGLE_CLIENT_ID')]);
            // Verifica el ID Token. Esto también valida la firma y la fecha de expiración.
            $payload = $client->verifyIdToken($googleIdToken);

            // Si el payload es nulo, el token no es válido
            if (!$payload) {
                return response()->json(['message' => 'Token de Google ID inválido o expirado.'], 401);
            }

            // Extrae los datos del usuario del payload de Google
            $googleId = $payload['sub']; // ID único de Google
            $email = $payload['email'];
            $fullName = $payload['name']; // El nombre completo de Google se mapea a 'full_name' en tu modelo
            $picture = $payload['picture'] ?? null; // URL de la foto de perfil (opcional)

            // Busca el usuario en tu base de datos por google_id
            $user = User::where('google_id', $googleId)->first();

            if (!$user) {
                // Si no se encuentra un usuario con ese google_id,
                // intenta encontrarlo por email.
                $user = User::where('email', $email)->first();

                if ($user) {
                    // Si existe un usuario con ese email pero sin google_id,
                    // significa que se registró convencionalmente. Vincula la cuenta de Google.
                    $user->google_id = $googleId;
                    $user->email_verified_at = now(); // Asume que Google ya verificó el email
                    $user->avatar = $picture; // Guarda el avatar si existe
                    $user->save();
                } else {
                    // Si no existe ningún usuario con ese google_id ni email,
                    // crea un nuevo usuario.
                    $user = User::create([
                        'full_name' => $fullName, // Usamos 'full_name' aquí
                        'email' => $email,
                        'google_id' => $googleId,
                        'password' => bcrypt(Str::random(16)), // Genera una contraseña aleatoria.
                                                              // No se usará para iniciar sesión con Google.
                        'email_verified_at' => now(), // Asume que Google ya verificó el email
                        'avatar' => $picture, // Guarda el avatar si existe
                    ]);
                }
            } else {
                // Si el usuario ya existe por google_id, puedes actualizar su avatar si ha cambiado
                // o si no lo tenías guardado previamente.
                if ($user->avatar !== $picture) {
                    $user->avatar = $picture;
                    $user->save();
                }
            }

            // Autentica al usuario en Laravel
            Auth::login($user);

            // Genera un token de sesión para el frontend usando Laravel Sanctum
            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'message' => 'Autenticación con Google exitosa.',
                'user' => $user,
                'token' => $token, // Envía el token de Sanctum al frontend
            ]);

        } catch (\Google\Exception $e) {
            // Manejo de errores específicos de la librería de Google (ej. token malformado)
            return response()->json(['message' => 'Error al verificar el token de Google ID: ' . $e->getMessage()], 401);
        } catch (ValidationException $e) {
            // Manejo de errores de validación de Laravel
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            // Manejo de cualquier otro error inesperado
            return response()->json(['message' => 'Ocurrió un error inesperado: ' . $e->getMessage()], 500);
        }
    }
}
