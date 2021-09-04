<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Models\Order;
use App\Models\Product;
use App\Models\OrderItems;
use App\Models\Transaction;

class OrderController extends Controller
{

    function __construct(){
    
    }
    
    //
     /**
     * Display a listing of the resource.
     * @param \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        //
        $response = [];

        $orders_per_page = 2;

        $page = $request->query('page');

        if( !ctype_digit($page) || $page < 1 ){
            $page = 1;
        }

        $offset = ($page - 1) * $orders_per_page;
        

        $orders = Order::where('user_id', Auth::user()->id);

        
        $orders_total =  $orders->count();
        
        $pages = ceil( $orders_total / $orders_per_page );
        
        
        $orders = $orders->offset($offset)->limit($orders_per_page)->get();
        

        foreach($orders as $order){

            $items = OrderItems::where('order_id', $order['id'])->get();

            $transaction = Transaction::where('order_id', $order['id'])->first();

            switch($order->status){
                case '0':
                    $order['status'] = 'Preparando';
                    break;
                case '1':
                    $order['status'] = 'Saiu pra entrega';
                    break;
                case '2':
                    $order['status'] = 'Entregue';
                    break;
            }

            
            if($transaction){
                switch($transaction['type']){
                    case '0':
                        $order['payment_type'] = 'Cartão de Debito';
                        break;
                    case '1':
                        $order['payment_type'] = 'Cartão de Crédito';
                        break;
                }
            }
            
            $productList = [];
        
            foreach($items as $item){
                
                $product = Product::find($item['product_id']);
                if($product){
                    $productList[] = $product;
                }
            }
    
            $order['products'] = $productList;  

        }

        $response['orders'] = $orders;
        $response['total'] = $orders_total;
        $response['current_page'] = $page < 1 ? 1 : intval($page);
        $response['total_pages'] = $pages;
              
        return response()->json( $response ); 


    }


    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id = 0)
    {
        //
        $response = [];

        $order = Order::where('id',$id,)->where('user_id', Auth::user()->id)->first();
        
        if(!$order){
            return response()->json(['error' => 'Unauthorized'], 401);
        } 

        $order['user'] =  Auth::user();

        $items = OrderItems::where('order_id', $order['id'])->get();
  
        $productList = [];

        foreach($items as $item){
            
            $product = Product::find($item['product_id']);
            if($product){
                $productList[] = $product;
            }
        }

        $order['products'] = $productList;
        
        return response()->json(['order' => $order]); 

    }

   
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
        
        $validator = Validator::make($request->all(), [
            'status' => 'required|numeric',
            ]); 

        if($validator->fails()){
            return response()->json([$validator->$errors], 400);
        }

        $transaction = Transaction::where('order_id', $id)->first();

        if($transaction){

            $transaction['status'] = $request->input('status');
            $transaction->save();

            return response()->json(['msg' => "Transaction status for order $id changed"], 200);
        }else{
            return response()->json(['error' => 'Invalid transaction data'], 400);
        }
        
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id = 0)
    {
        //
        $response = [];
    }

    /**
     * Calculate the delivery for the order.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function deliveryCalculate(Request $request, $id = 0)
    {
        //
        $response = [];
        
        $request->validate([
            'id_address' => 'required|numeric',
            'street' => 'required|string|min:3|max:200',
            'zipcode' => 'required|numeric|max:500',
            'city' => 'required|string|max:50',
            'state' => 'required|numeric',
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //

        $status = ['pedido','saiu pra entrega', 'entregue','cancellado'];
       
        if(!$request->input('products')){
            return response()->json(['error' => 'The products data is empty or contains an error'],400);
        }

        


        $validator = Validator::make($request->all(), [
                'products.*.id' => 'required|numeric',
                'products.*.qt' => 'required|numeric',
                'paymentType' => 'required|numeric',
                'deliveryCost' => 'nullable|regex:/^\d+(\.\d{1,2})?$/',
                'cupom' => 'nullable|string|max:200',
                'street' => 'required|string|max:80',
                'complement' => 'nullable|string|max:80',
                'number' => 'required|numeric',
                'postalCode' => 'required|string|max:10',
                'city' => 'nullable|string|max:200',
                'state' => 'nullable|string|min:2|max:2',
                'transactionCode' => 'required|string|max:200'
              ]);  

        if($validator->fails()){
            return response()->json([$validator->errors()], 400);
        }

        //id_address, cumpom, products[{id, qt}], payment_data , payment_type, delivery_cost 

        $total = 0;

        $products = $request->input('products');
        
        foreach($products as $item){
            $product = Product::find($item['id']);
            if(!$product){
                return response()->json(['error' => "Product $id doesn't exist"], 400);
            }
            $total += floatval($product->price) * intval($item['qt']);
        }
        
        /*
            Payment process
        */

        $order = new Order;
        $order->user_id = Auth::user()->id;
        $order->total = $total;
        
        $order->street = $request->input('street');
        $order->number = $request->input('number');
        $order->complement = $request->input('complement');
        $order->postal_code = $request->input('postalCode');
        $order->city = $request->input('city');
        $order->state = $request->input('state');
        $order->delivery_cost = $request->input('deliveryCost');

        
        $order->save();

       
        foreach($products as $item ){
            $OrderItems = new OrderItems;
            $OrderItems->product_id = $item['id'];
            $OrderItems->qt = $item['qt'];
            $OrderItems->order_id = $order->id;
            $OrderItems->save();
        }

        $transactionCode = $request->input('transactionCode');
        $paymentType = $request->input('paymentType', 1);

        $this->createTransaction( $transactionCode, $order->id);



        return response()->json(['msg' => "Order created successfully", 'order' => $order]);

    }

    /**
     * Create a new transaction
     *
     * @param  Array  $data
     * @return Array
     */
    private function createTransaction(String $transactionCode, int $orderId, int $paymentType )
    {
        //
        $transaction = new Transaction;

        $transaction->code = $transactionCode;
        //$transaction->mode = $paymentType;
        $transaction->type = 1;
        //$transaction->status = '1';
        $transaction->user_id = Auth::user()->id;
        $transaction->order_id = $orderId;
        $transaction->save();
          

    }


    /**
     * Genarate the checkout code for Pagseguro
     *
     * @param  Request  $id
     * @return \Illuminate\Http\Response
     */
    public function generateCheckoutCode(Request $request)
    {
        //
        $validator = Validator::make($request->all(), [
                        'products.*.id' => 'required|numeric',
                        'products.*.name' => 'required|string',
                        'products.*.price' => 'required|required|regex:/\d+.\d{2}/',//regex:/^\d+(\.\d{1,2})?$/',
                        'products.*.qt' => 'required|integer',
                        'clientName'  => 'required|string|max:50',
                        'clientCPF' => 'nullable|string|max:20',
                        'cupom' => 'nullable|string|max:200',
                        'street' => 'required|string|max:80',
                        'number' => 'required|numeric',
                        'district' => 'required|string|max:50',
                        'postalCode' => 'required|string|max:10',
                        'city' => 'required|string|max:50',
                        'state' => 'required|string|max:2',
                        'country' => 'required|string|max:50',
                        'deliveryCost' => 'required|regex:/^\d+(\.\d{1,2})?$/',
                        'redirectURL' => 'required|string|max:50',
                        'notificationURL' => 'required|string|max:50'
        ]);

        $products = $request->input('products');

        if(count($request->input('products')) < 1){
            return response()->json(['error' => 'The Shopping Cart is empty'], 400);
        }

        if($validator->fails()){
            return response()->json([$validator->errors()], 400);
        }

        
        \PagSeguro\Library::initialize();
        \PagSeguro\Library::cmsVersion()->setName("Nome")->setRelease("1.0.0");
        \PagSeguro\Library::moduleVersion()->setName("Nome")->setRelease("1.0.0");
        
        $payment = new \PagSeguro\Domains\Requests\Payment();

        foreach($products as $prod){
           $payment->addItems()->withParameters(
                $prod['id'],
                $prod['name'],
                $prod['qt'],
                $prod['price'],
            ); 
        }
        

       /* $payment->addItems()->withParameters(
            '0002',
            'Notebook preto',
            2,
            430.00
        ); */

        $payment->setCurrency("BRL");
        $payment->setReference("LIBPHP000001");

        $payment->setRedirectUrl(env('APP_URL'). "/nofitication");

        // Set your customer information.
        $payment->setSender()->setName($request->input('clientName'));//'João Comprador');
        //$payment->setSender()->setEmail($request->input('senderEmail'));//'email@comprador.com.br');
       /* $payment->setSender()->setPhone()->withParameters(
            $request->input('senderAreaCode'),//11,
            $request->input('senderPhone')//56273440
        ); */
        $payment->setSender()->setDocument()->withParameters(
            'CPF',
            $request->input('clientCPF')
        );

        $payment->setShipping()->setAddress()->withParameters(
            $request->input('street'),// 'Av. Brig. Faria Lima', $request->input('status')
            $request->input('number'),// '1384',
            $request->input('district'),// 'Jardim Paulistano',
           $request->input('postalCode'),// '01452002',
           $request->input('city'),// 'São Paulo',
           $request->input('state'),// 'SP',
           $request->input('country'),// 'BRA',
           $request->input('complement')// 'apto. 114'
        );
        $payment->setShipping()->setCost()->withParameters($request->input('deliveryCost'));//20.00);
        $payment->setShipping()->setType()->withParameters(\PagSeguro\Enum\Shipping\Type::NOT_SPECIFIED);

       
        //Add items by parameter
      //  $payment->addParameter()->withParameters('itemId', '0003')->index(3);
      //  $payment->addParameter()->withParameters('itemDescription', 'Notebook Rosa')->index(3);
      //  $payment->addParameter()->withParameters('itemQuantity', '1')->index(3);
      //  $payment->addParameter()->withParameters('itemAmount', '201.40')->index(3);


        //Add items by parameter using an array
        $payment->addParameter()->withArray(['notificationURL', env('APP_URL').'/nofitication']);
        
        
        $payment->setRedirectUrl(env('APP_URL')."/nofitication");
        $payment->setNotificationUrl(env('APP_URL')."/nofitication");

      
        
        try {
            $onlyCheckoutCode = true;
          
            $result = $payment->register(
                \PagSeguro\Configuration\Configure::getAccountCredentials(),
                $onlyCheckoutCode
             );
            
           
            return response()->json(['code' => $result->getCode()]);
            
                
        } catch (\Exception $e) {

            $xml = simplexml_load_string($e->getMessage());
            $errorMsg = $xml->error->message ?? $e->getMessage();

            return response()->json(['error' => $errorMsg]);
        }  
        
    }

    /**
     * Create a new transaction
     *
     * @param  Request  $request
     * @return \Illuminate\Http\Response
     */
    public function notifyPayment(Request $request)
    {
        //

        \PagSeguro\Library::initialize();
        \PagSeguro\Library::cmsVersion()->setName("Nome")->setRelease("1.0.0");
        \PagSeguro\Library::moduleVersion()->setName("Nome")->setRelease("1.0.0");

        try {
            if (\PagSeguro\Helpers\Xhr::hasPost()) {
                $response = \PagSeguro\Services\Transactions\Notification::check(
                    \PagSeguro\Configuration\Configure::getAccountCredentials()
                );
            } else {
                throw new \InvalidArgumentException($_POST);
            }

            print_r($response);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }

          
        
    }
        


}