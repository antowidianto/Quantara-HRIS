<?php

namespace App\Filters;

use App\Libraries\Approval\PrivilegedActionApprovalService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

final class PrivilegedActionApprovalFilter implements FilterInterface
{
    public function before(RequestInterface $request,$arguments=null)
    {
        $group=strtoupper((string)($arguments[0]??'')); if(!in_array($group,['RBAC','PII_EXPORT','INTEGRATION'],true))return $this->fail('Privileged action group invalid',500);
        $tenant=Services::tenant();$companyId=(int)$tenant->getCompanyId();
        if($companyId<=0){$body=method_exists($request,'getJSON')?$request->getJSON(true):[];$companyId=(int)($request->getHeaderLine('X-Company-Id')?:((is_array($body)?$body['company_id']??0:0)));}
        if($companyId<=0)return $this->fail('Company target wajib untuk privileged action',422);
        $method=strtoupper($request->getMethod());$path='/'.ltrim((string)$request->getUri()->getPath(),'/');$query=(string)$request->getUri()->getQuery();$body=(string)$request->getBody();
        $hash=hash('sha256',$companyId.'|'.$tenant->getUserId().'|'.$group.'|'.$method.'|'.$path.'|'.$query.'|'.$body);
        try{$result=(new PrivilegedActionApprovalService())->authorizeOrSubmit($companyId,(int)$tenant->getUserId(),$group,$method,$path,$hash);}
        catch(\Throwable $e){return $this->fail($e->getMessage(),409);}
        if($result['state']==='AUTHORIZED')return;
        return Services::response()->setStatusCode(202)->setJSON(['status'=>'success','message'=>'Aksi berisiko diajukan dan menunggu approval checker. Ulangi aksi yang sama setelah disetujui.','data'=>['approval_state'=>'PENDING','request_id'=>(int)$result['request']['id'],'request_number'=>$result['request']['request_number']]]);
    }
    public function after(RequestInterface $request,ResponseInterface $response,$arguments=null){}
    private function fail(string $message,int $status):ResponseInterface{return Services::response()->setStatusCode($status)->setJSON(['status'=>'error','message'=>$message]);}
}
