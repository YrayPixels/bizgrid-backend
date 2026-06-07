<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class TransactionsController extends Controller
{
    public function get_transaction($id)
    {

        $transaction = DB::table('transactions')->where('unique_id', $id)->first();
        return response()->json($transaction, 200);
    }


    public function add_transaction(Request $request)
    {

        $data = [
            'blockhash' => $request->blockhash,
            'lastblockheight' => $request->lastblockheight,
            'unique_id' => $request->unique_id,
            'serialized' => $request->serialized,
            'status' => 'pending'
        ];

        $insert = DB::table('transactions')->insert($data);

        if ($insert) {
            return response()->json(["message" => "Transaction Saved"], 200);
        } else {
            return response()->json(["message" => "Transaction Failed"], 200);
        }
    }
}
