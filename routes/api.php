use Illuminate\Http\Request;

Route::post('/addWordAPI', function (Request $request) {
    return response()->json([
        'status' => 'worked',
        'received_data' => $request->all()
    ]);
});
