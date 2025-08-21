<?php

namespace App\Http\Controllers;

use App\Models\Specie;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SpecieController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (Auth::guard('api')->check()) {
            $user_id = Auth::guard('api')->user()->id;
            $species = Specie::where('user_id', $user_id)->get();

            return response()->json([
                'status' => true,
                'species' => $species,
                'message' => 'Especies obtenidas exitosamente.'
            ])->setStatusCode(200);
        }

        return response()->json([
            'status' => false,
            'message' => 'No autorizado. Por favor, inicia sesión.'
        ], 401);
        
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'scientific_name' => 'required|string|max:255',
        ]);

        $specie = Specie::create($request->all());

        return response()->json([
            'status' => true,
            'specie' => $specie,
            'message' => 'Especie creada exitosamente.'
        ])->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Specie $specie)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Specie $specie)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Specie $specie)
    {
        //
    }
}
