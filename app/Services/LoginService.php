<?php

namespace App\Services;

use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Models\User;
use App\Mail\ResetPassword;
use DB;
use Hash;
use Carbon\Carbon;
class LoginService
{

    use ApiResponse;

    public function login($credentials)
    {
        try {
            $validator = Validator::make($credentials, [
                'email' => ['required', 'string'],
                'password' => ['required'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors(), 422);
            }

            $user = User::where('email', 'LIKE', $credentials['email'] . '%')->first();

            if (!$user || !Hash::check($credentials['password'], $user->password)) {
                return $this->errorResponse(['Los datos introducidos son inválidos, verifica e intenta nuevamente'], 401);
            }

            $token = $user->createToken('USER_AUTH_TOKEN')->accessToken;

            if($user->token_revoked){
                $user->update([
                    "token_revoked" => 0
                ]);
            }

            return $this->successResponse([
                'success' => true,
                'access_token' => $token,
                'token_type' => 'Bearer',
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al iniciar sesión');
        }
    }

    public function logout()
    {
        try {
            $user = Auth::user();
            $user->token_revoked = 1;
            $user->save();

            Auth::logout();

            return $this->successResponse([
                'success' => true,
            ]);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al cerrar sesión');
        }
    }

    public function changePassword($params)
    {

        DB::beginTransaction();

        try {
            $validator = Validator::make($params, [
                'current_password' => ['required'],
                'new_password' => ['required', 'min:8'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors(), 422);
            }

            $user = Auth::user();

            if (!Hash::check($params['current_password'], $user->password)) {
                return $this->errorResponse('La contraseña actual no es correcta', 401);
            }

            $user->password = Hash::make($params['new_password']);
            $user->save();

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Contraseña actualizada correctamente',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return $this->handlerException('Error al cambiar la contraseña');
        }
    }


    public function sendPasswordResetLink($email)
    {

        try {

            $validator = Validator::make(['email' => $email], [
                'email' => ['required', 'email'],
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors(), 422);
            }

            // Generar un token de un solo uso
            $token = Str::random(30);

            // Guardar el token en cache con una duración de 10 minutos
            DB::table('password_reset_tokens')->insert([
                'email' => $email,
                'token' => $token,
            ]);

            //$resetLink = url('/password/reset', $token);
            Mail::to($email)->send(new ResetPassword($token));


            return $this->successResponse([
                'success' => true,
                'message' => 'El enlace para restablecer la contraseña ha sido enviado a su correo electrónico'
            ]);

        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return $this->handlerException('Error al enviar el enlace para restablecer la contraseña');
        }

    }

    public function resetPassword($params)
    {
        DB::beginTransaction();

        try {

            $token = DB::table('password_reset_tokens')->where('token', $params['token'])->first();

            if (!$token) {
                return $this->errorResponse('Token invalido', 401);
            }

            $user = User::where('email', $token->email)->first();

            if (!$user) {
                return $this->errorResponse('Usuario no encontrado', 404);
            }

            $user->password = Hash::make($params['password']);
            $user->save();

            DB::table('password_reset_tokens')->where('token', $params['token'])->delete();

            DB::commit();

            return $this->successResponse([
                'success' => true,
                'message' => 'Contraseña restablecida correctamente',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            return $this->handlerException('Error al restablecer la contraseña');
        }
    }


}
