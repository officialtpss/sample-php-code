<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\ZohobooksproductsController;
use App\Http\Controllers\Api\ZohocrmController;
use App\Traits\writeLogFile;
use App\Traits\curl;
use App\Traits\zohoCrm;
use App\Syncinqueue;

class ZohocrmproductsController extends ZohocrmController
{
    use writeLogFile, curl, zohoCrm;

    private $scope = 'ZohoCRM.modules.products.ALL';

    /**
     * Variables of Class 
     * i.e used in ZOHO BOOKS
     */
    private $oAuthCode;

    /**
     * Set Global Class Variables
     */
    public function __construct()
    {
    	$this->oAuthCode = $this->oAuthCode();
        $this->baseUrl = $this->baseUrlCrm();
    }

    /**
     * Update Product Listener
     */
    public function updateProductListener(Request $request)
    {
        if($request->isMethod('post'))
        {
            $response = array();

            $input = $request->all();

            $crmProductId = $input['crmProductId'];
            $crmProductInfoTmp = $this->fetechProductInfo($crmProductId);

            if ($crmProductInfoTmp['code'] == 200) 
            {
                if (!empty($crmProductInfoTmp['info']['Bigcommerce_Unique_ID'])) 
                {
                    $actionUniqueId = $crmProductInfoTmp['info']['Bigcommerce_Unique_ID'];
                    $checkQueue = Syncinqueue::where([['action', 'product'],['actionUniqueId', $actionUniqueId]])->first();

                    $executeBooks = 0;
                    if (is_null($checkQueue)) 
                    {
                        $tmpData = array(
                            'isZohoCrm' => 1, 
                            'startPoint' => 'zohoCrm', 
                            'actionUniqueId' => $actionUniqueId,
                            'action' => 'product'
                        );

                        Syncinqueue::create($tmpData);
                        $executeBooks = 1;                      

                    } else {

                        $checkQueue = $checkQueue->toArray();

                        if (($checkQueue['startPoint'] == 'zohoCrm') && ($checkQueue['isZohoCrm'] == 1) && ($checkQueue['isZohoBooks'] == 0)) 
                        {
                            $tmpData = array('isZohoCrm' => 1);
                            Syncinqueue::find($checkQueue['id'])->update($tmpData);
                            
                            $executeBooks = 1;

                        } elseif (($checkQueue['startPoint'] == 'bigCommerce') && ($checkQueue['isBigcommerce'] == 1) && ($checkQueue['isZohoCrm'] == 1) && ($checkQueue['isZohoBooks'] == 0)) {

                            $queryResponse = Syncinqueue::find($checkQueue['id'])->delete();

                            $tmpData = array(
                                'isZohoCrm' => 1, 
                                'startPoint' => 'zohoCrm', 
                                'actionUniqueId' => $actionUniqueId,
                                'action' => 'product'
                            );

                            Syncinqueue::create($tmpData);
                            $executeBooks = 1;                             

                        } elseif ($checkQueue['startPoint'] == 'zohoBooks') {

                            $response['code'] = 500;
                            $response['message'] = 'Webhook hitting initial point again';
                            $response['error'] = $actionUniqueId;

                            $queryResponse = Syncinqueue::find($checkQueue['id'])->delete();
                            $response['queryResponse'] = $queryResponse;                            

                        } else {

                            if ($checkQueue['isZohoCrm'] == 1) {

                                $response['code'] = 500;
                                $response['message'] = 'loop of '.$checkQueue['startPoint'].' is in progress.';
                                $response['error'] = $actionUniqueId;
                                
                            } else {

                                $tmpData = array('isZohoCrm' => 1);
                                Syncinqueue::find($checkQueue['id'])->update($tmpData);
                                $executeBooks = 1;
                            }
                        }
                    }

                    if ($executeBooks == 1) 
                    {
                        $zohoBookObject = new ZohobooksproductsController;
                        $responseFromBooks = $zohoBookObject->productExecution($crmProductInfoTmp['info']);

                        $response = $responseFromBooks;
                    }

                } else {

                    $response['code'] = 500;
                    $response['message'] = 'Action Unique Id field is empty';
                    $response['error'] = $crmProductInfoTmp;
                }
            } else {
                $response = $crmProductInfoTmp;
            }

            /**
             * Use Trait to Write Log File in Storage Path
             */
            $this->logFile(json_encode($response, true), 'updateProductListenerCRM');
        }
    }

    /**
     * Fetch Product with Product id
     */
    private function fetechProductInfo($crmProductId)
    {   
        $url = $this->baseUrl.'Products/'.$crmProductId;
        
        $header = array(
            "Content-Type:application/x-www-form-urlencoded",
            "Authorization:Zoho-oauthtoken ".$this->oAuthCode
        );

        $output = $this->writeCurlGet($url, $header);
        $output = json_decode($output, true);

        $response = array();
        if (isset($output['data'][0])) 
        {
            $response['code'] = 200;
            $response['message'] = 'SUCCESS';
            $response['info'] = $output['data'][0];
        } else {
            $response['code'] = 500;
            $response['message'] = 'Error';
            $response['error'] = $output;
        }

        return $response;
    }

    /*****************************************************************/
    /*****************************************************************/
    /*****************************************************************/

    /**
     * Fetch Store Name with help of Email
     */
    public function fetchVendorOfCrm($vendorName)
    {
        $url = $this->baseUrl."Vendors/search?criteria=(Vendor_Name:equals:".$vendorName.")";

        $headers = array(
            "Content-Type:application/x-www-form-urlencoded",
            "Authorization:Zoho-oauthtoken ".$this->oAuthCode
        );

        $output = $this->writeCurlGet($url, $headers);
        $output = json_decode($output, true);

        if (!is_null($output)) {

            $storeArray = array(
                'code' => 200, 
                'message' => 'Successfully',
                'info' => $output['data'][0]
            );

        } else {

            $storeArray = array(
                'code' => 404, 
                'message' => 'Vendor does not exist.'
            );

        }

        return $storeArray;
    }

    /**
     * Create Vendor based on brand name
     */
    public function createVendorofCrm($vendorName)
    {
        $url = $this->baseUrl."Vendors";

        $headers = array(
            "Content-Type:application/x-www-form-urlencoded",
            "Authorization:Zoho-oauthtoken ".$this->oAuthCode,
            "scope:ZohoCRM.modules.custom.ALL"
        );

        $data['data'][] = array(
            'Vendor_Name' => $vendorName
        );

        $data = json_encode($data, true);

        $output = $this->writeCurl($url, $data, $headers, 'POST');
        $output = json_decode($output, true);
        return $output;
    }

    /**
     * Search Product by unique Id
     */
    public function searchProductZohoCrm($uniqueId)
    {
        $url = $this->baseUrl.'Products/search?criteria=(Bigcommerce_Unique_ID:equals:'.$uniqueId.')';

        $headers = array(
            "Content-Type:application/x-www-form-urlencoded",
            "Authorization:Zoho-oauthtoken ".$this->oAuthCode
        );

        $output = $this->writeCurlGet($url, $headers);
        $output = json_decode($output, true);
        
        $response = array();
        if (isset($output['data'])) 
        {
            $response['code'] = 200;
            $response['message'] = 'SUCCESS';
            $response['data'] = $output['data'][0];
        } else {
            $response['code'] = 500;
            $response['message'] = 'Error';
            $response['error'] = $output;           
        }

        return $response;
    }

    /**
     * Fetch Store Name with help of Email
     */
    public function fetchStoreOfCrm($storeEmail)
    {
        $url = "https://crm.zoho.com/crm/v2/Stores/search?criteria=(Email:equals:".$storeEmail.")";

        $headers = array(
            "Content-Type:application/x-www-form-urlencoded",
            "Authorization:Zoho-oauthtoken ".$this->oAuthCode,
            "scope:ZohoCRM.modules.custom.ALL"
        );

        $output = $this->writeCurlGet($url, $headers);
        $output = json_decode($output, true);

        if (!is_null($output)) {

            $storeArray = array(
                'code' => 200, 
                'message' => 'Successfully',
                'storeId' => $output['data'][0]['id'],
                'storeCurrency' => $output['data'][0]['Currency'],
                'storeModName' => $output['data'][0]['Name']
            );

        } else {

            $storeArray = array(
                'code' => 404, 
                'message' => 'Store does not exist.'
            );

        }

        return $storeArray;
    }

    /**
     * @migerateProductsZohoCrm
     * 
     * Migerate Products from BC to CRM
     * Add if not exist update if exist
     */
    public function productExecution($product, $tokenArray)
    {
		
        $response = array();
        $productsArray = array();

        $storeResponse = $this->fetchStoreOfCrm($tokenArray['storeEmail']);

        $brandName = str_replace(' ', '', $product['brandName']);
        $storeModName = str_replace(' ', '', $storeResponse['storeModName']);

        $vendorName = $brandName.'@'.$storeModName;
        $vendorResponse = $this->fetchVendorOfCrm($vendorName);

        $vendorId = 0;
        if (($vendorResponse['code'] == 404) && ($brandName != 'default')) 
        {
            if (!empty($brandName))
            {
                $createVendorResponse = $this->createVendorofCrm($vendorName);
                
                if ($createVendorResponse['data'][0]['code'] == 'SUCCESS') {
                    $vendorId = $createVendorResponse['data'][0]['details']['id'];
                }
            }

        } else {

            if ($vendorResponse['code'] == 200) 
            {
                if (isset($vendorResponse['info']['id'])) 
                {
                    $vendorId = $vendorResponse['info']['id'];
                }
            }
        }

        if ($storeResponse['code'] == 200) 
        {
			
			if(isset($product['additionalFieldArray'])){					
				$additionalFieldArray = array();				
				$additionalFields = $product['additionalFieldArray'];
				
				foreach($additionalFields as $fieldKey=>$fieldVal){
					$fieldName = $fieldVal['name'];
					$fieldValue = $fieldVal["text"];					
					$additionalFieldArray[$fieldName] = $fieldValue;
				}
			}	
				
				
            $tmpArray['data'][] = array(
                'Product_Name' => $product['name'],
                'Unit_Price' => $product['price'],
                'Cost_Price' => $product['cost_price'],
                'Retail_Price' => $product['retail_price'],
                'Sale_Price' => $product['sale_price'],
                'Fixed_Shipping_Price' => $product['fixed_cost_shipping_price'],
                'Warranty_Information' => $product['warranty'],
                'Product_Code' => $product['sku'],
                'Product_type' => $product['type'],
                'Weight' => $product['weight'],
                'Width' => $product['width'],
                'Height' => $product['height'],
                'Depth' => $product['depth'],
                'Description' => $product['description'],
                'Page_Title' => $product['page_title'],       
                'Meta_Keywords' => $product['meta_keywords'],         
                'Meta_Description' => $product['meta_description'],
                'Image1' => $product['primary_image']['standard_url'],
                'Bigcommerce_ID' => $product['id'],
                'Vendor_Name' => array(
                    'id'=>$vendorId
                ),
                'Bigcommerce_Unique_ID' => $product['id'].'_'.$tokenArray['storeName'],
                'Store'=>array(
                    'id'=>$storeResponse['storeId']
                ),
                'Product_Currency' => $storeResponse['storeCurrency'],
                'Image1' => $product['imagesArray'][0]['standard_url'] ?? '',
                'Image2' => $product['imagesArray'][1]['standard_url'] ?? '',
                'Image3' => $product['imagesArray'][2]['standard_url'] ?? '',
                'Image4' => $product['imagesArray'][3]['standard_url'] ?? '',
                'Image5' => $product['imagesArray'][4]['standard_url'] ?? '',
                'Image6' => $product['imagesArray'][5]['standard_url'] ?? '',
				'qb_product_sales_account' => $additionalFieldArray['qb_product_sales_account'] ?? '',
				'qb_cogs_account' => $additionalFieldArray['qb_cogs_account'] ?? '',
				'qb_shipping_line' => $additionalFieldArray['qb_shipping_line'] ?? '',
				'Dropship_Print' => $additionalFieldArray['Dropship_Print'] ?? '',
				'qb_income_account' => $additionalFieldArray['qb_income_account'] ?? '',                
				'CustomField_8' => $additionalFieldArray['CustomField_8'] ?? '',                
				'CustomField_9' => $additionalFieldArray['CustomField_9'] ?? '',                
				'CustomField_10' => $additionalFieldArray['CustomField_10'] ?? '',
				'CustomField_11' => $additionalFieldArray['CustomField_11'] ?? '',
				'CustomField_12' => $additionalFieldArray['CustomField_12'] ?? '',
				'CustomField_13' => $additionalFieldArray['CustomField_13'] ?? '',
				'CustomField_14' => $additionalFieldArray['CustomField_14'] ?? '',
				'CustomField_15' => $additionalFieldArray['CustomField_15'] ?? '',
				'CustomField_16' => $additionalFieldArray['CustomField_16'] ?? '',
				'CustomField_17' => $additionalFieldArray['CustomField_17'] ?? '',
				'CustomField_18' => $additionalFieldArray['CustomField_18'] ?? '',
				'CustomField_19' => $additionalFieldArray['CustomField_19'] ?? '',
				'CustomField_20' => $additionalFieldArray['CustomField_20'] ?? '',
				'Vendor_Name1' => $additionalFieldArray['Vendor Name'] ?? '',
				
            );
            
            if ($vendorId == 0) 
            {
                unset($tmpArray['data'][0]['Vendor_Name']);
            }

            $checkProduct = $this->searchProductZohoCrm($tmpArray['data'][0]['Bigcommerce_Unique_ID']);

            if ($checkProduct['code'] == 200) 
            {
                $productId = $checkProduct['data']['id'];
                $response = $this->updateProductsZohoCRM($productId, $tmpArray); 
                
            } else {
                $response = $this->addProductsZohoCRM($tmpArray);
            }
        }

        return $response;
    }

    /**
     * Add Product in Zoho Crm
     */
    public function addProductsZohoCRM($products)
    {
        $url = $this->baseUrl.'Products';
            
        $headers = array(
            "Content-Type:application/x-www-form-urlencoded",
            "Authorization:Zoho-oauthtoken ".$this->oAuthCode
        );
                        
        $data = json_encode($products, true);
        $methodName = "POST";

        $output = $this->writeCurl($url, $data, $headers, $methodName);
        $output = json_decode($output, true);

        $response = array();
        if (isset($output['data'][0]) && ($output['data'][0]['code'] == 'SUCCESS')) 
        {
            $response['code'] = 200;
            $response['message'] = 'Created';
            $response['info'] = $output['data'][0];
            
        } else {
            $response['code'] = 500;
            $response['message'] = 'Error';
            $response['error'] = $output;
        }
         
        return  $response;
    }


    /**
     * Update Product of Zoho CRM
     */
    public function updateProductsZohoCRM($productId, $products)
    {   
        $response = array();
        $url = $this->baseUrl.'Products/'.$productId;
        
        $headers = array(
            "Content-Type:application/x-www-form-urlencoded",
            "Authorization:Zoho-oauthtoken ".$this->oAuthCode
        );

        $data = json_encode($products, true);

        $output = $this->writeCurl($url, $data, $headers, 'PUT');
        $output = json_decode($output, true);
        
        if (isset($output['data'][0]) && ($output['data'][0]['code'] == 'SUCCESS')) 
        {
            $response['code'] = 200;
            $response['message'] = 'Updated';
            $response['info'] = $output['data'][0];

        } else {
            $response['code'] = 500;
            $response['message'] = 'Error';
            $response['error'] = $output;
        }

        return $response;       
    }

}
