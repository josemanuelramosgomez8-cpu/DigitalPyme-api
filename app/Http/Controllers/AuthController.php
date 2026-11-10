<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credenciales = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $credenciales['email'])->first();

        if (!$user || !Hash::check($credenciales['password'], $user->password) || !$user->is_admin || !$user->is_active) {
            return response()->json(['mensaje' => 'Correo o contraseña incorrectos'], 422);
        }

        $token = $user->createToken('admin')->plainTextToken;

        return response()->json([
            'token'   => $token,
            'usuario' => ['name' => $user->name, 'email' => $user->email],
        ]);
    }

    public function clientLogin(Request $request)
    {
        $credenciales = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $credenciales['email'])->first();

        if (!$user || !Hash::check($credenciales['password'], $user->password) || !$user->is_client || !$user->is_active || !$user->client()->exists()) {
            return response()->json(['mensaje' => 'Correo o contraseña incorrectos'], 422);
        }

        $token = $user->createToken('client')->plainTextToken;

        return response()->json([
            'token' => $token,
            'usuario' => [
                'name' => $user->name,
                'email' => $user->email,
                'must_change_password' => $user->must_change_password,
            ],
        ]);
    }

    public function clientChangePassword(Request $request)
    {
        $datos = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:12|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($datos['current_password'], $user->password)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'current_password' => 'La contraseña actual no es correcta.',
            ]);
        }

        $user->password = $datos['password'];
        $user->must_change_password = false;
        $user->save();
        $user->tokens()->delete();

        return response()->json(['mensaje' => 'Contraseña actualizada. Inicia sesión nuevamente.']);
    }

    public function resetPassword(Request $request)
    {
        $datos = $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:12|confirmed',
        ]);

        $status = \Illuminate\Support\Facades\Password::reset(
            $datos,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'must_change_password' => false,
                ])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== \Illuminate\Support\Facades\Password::PASSWORD_RESET) {
            return response()->json([
                'mensaje' => 'El enlace no es válido o venció. Solicita uno nuevo al administrador.',
            ], 422);
        }

        return response()->json(['mensaje' => 'Contraseña restablecida. Ya puedes iniciar sesión.']);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['mensaje' => 'Sesión cerrada']);
    }
}
