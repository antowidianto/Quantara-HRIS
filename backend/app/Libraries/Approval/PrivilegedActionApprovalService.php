<?php

namespace App\Libraries\Approval;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

final class PrivilegedActionApprovalService
{
    /** @var callable(array<string,mixed>):array<string,mixed>|null */ private $approvalStarter;
    public function __construct(private readonly ?BaseConnection $connection=null, ?callable $approvalStarter=null) { $this->approvalStarter=$approvalStarter; }

    /** @return array{state:string,request:array<string,mixed>,approval?:array<string,mixed>} */
    public function authorizeOrSubmit(
        int $companyId,
        int $userId,
        string $group,
        string $method,
        string $path,
        string $requestHash,
        ?string $requestPayload = null
    ): array
    {
        $db=$this->db(); $existing=$db->table('privileged_action_requests')->where('company_id',$companyId)->where('submitted_by',$userId)->where('request_hash',$requestHash)->where('deleted_at IS NULL')->orderBy('id','DESC')->get()->getRowArray();
        if ($existing && $existing['status']==='APPROVED' && empty($existing['used_at']) && !empty($existing['expires_at']) && $existing['expires_at'] >= date('Y-m-d H:i:s')) {
            $now=date('Y-m-d H:i:s'); $db->table('privileged_action_requests')->where('id',(int)$existing['id'])->where('status','APPROVED')->where('used_at IS NULL')->update(['status'=>'USED','used_at'=>$now,'updated_at'=>$now]);
            if ($db->affectedRows()!==1) throw new \RuntimeException('Otorisasi privileged action sudah digunakan');
            $existing['status']='USED'; $existing['used_at']=$now; return ['state'=>'AUTHORIZED','request'=>$existing];
        }
        if ($existing && $existing['status']==='PENDING_APPROVAL') return ['state'=>'PENDING','request'=>$existing];
        $actor=$db->table('users')->where('id',$userId)->where('is_active',1)->groupStart()->where('company_id',$companyId)->orWhere('company_id IS NULL')->groupEnd()->get()->getRowArray();
        if(!$actor) throw new \RuntimeException('Privileged action maker tidak valid untuk tenant target');
        $employeeId=(int)($actor['employee_id']??0);
        if($employeeId<=0){$subject=$db->table('employees')->select('id')->where('company_id',$companyId)->where('is_active',1)->where('deleted_at IS NULL')->orderBy('id','ASC')->get()->getRowArray();$employeeId=(int)($subject['id']??0);}
        if($employeeId<=0) throw new \RuntimeException('Tenant target belum memiliki employee aktif sebagai subject approval');
        $now=date('Y-m-d H:i:s'); $db->transBegin();
        try {
            $insert = [
                'company_id' => $companyId,
                'request_number' => 'PAR-' . date('YmdHis') . '-' . strtoupper(substr(hash('sha256', $requestHash . '|' . microtime(true)), 0, 8)),
                'action_group' => $group,
                'http_method' => $method,
                'request_path' => $path,
                'request_hash' => $requestHash,
                'status' => 'PENDING_APPROVAL',
                'submitted_by' => $userId,
                'submitted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($group === 'RBAC'
                && $requestPayload !== null
                && $db->fieldExists('request_payload', 'privileged_action_requests')
            ) {
                // Payload RBAC hanya berisi metadata role + permission ID.
                // Batasi ukuran sebagai defense-in-depth terhadap body abnormal.
                $insert['request_payload'] = mb_substr($requestPayload, 0, 262144);
            }
            $db->table('privileged_action_requests')->insert($insert);
            $id=(int)$db->insertID(); $params=['company_id'=>$companyId,'module'=>'PRIVILEGED_ACTION','entity_table'=>'privileged_action_requests','entity_id'=>$id,'employee_id'=>$employeeId,'meta'=>['submitted_by'=>$userId,'action_group'=>$group,'http_method'=>$method,'request_path'=>$path,'request_hash'=>$requestHash,'is_rbac'=>$group==='RBAC'?1:0,'is_pii_export'=>$group==='PII_EXPORT'?1:0,'is_integration_secret'=>$group==='INTEGRATION'?1:0]];
            $approval=$this->approvalStarter?($this->approvalStarter)($params):Services::approval()->start($params);
            if(!$db->transStatus()) throw new \RuntimeException('Gagal membuat approval privileged action'); $db->transCommit();
            return ['state'=>'PENDING','request'=>$this->request($id,$companyId),'approval'=>$approval];
        } catch(\Throwable $e){$db->transRollback();throw $e;}
    }

    /** @param array<string,mixed> $approvalRequest @return array<string,mixed> */
    public function approve(array $approvalRequest,int $userId):array
    { $id=(int)$approvalRequest['entity_id'];$companyId=(int)$approvalRequest['company_id'];$r=$this->request($id,$companyId);if($r['status']!=='PENDING_APPROVAL')throw new \RuntimeException('Privileged action tidak pending');$now=date('Y-m-d H:i:s');$expires=date('Y-m-d H:i:s',time()+86400);$this->db()->table('privileged_action_requests')->where('id',$id)->where('status','PENDING_APPROVAL')->update(['status'=>'APPROVED','approved_by'=>$userId,'approved_at'=>$now,'expires_at'=>$expires,'updated_at'=>$now]);return ['request_id'=>$id,'status'=>'APPROVED','expires_at'=>$expires,'action_group'=>$r['action_group']]; }
    /** @return array<string,mixed> */
    public function reject(int $id,int $companyId,int $userId,string $reason):array
    {if(mb_strlen(trim($reason))<5)throw new \InvalidArgumentException('Alasan penolakan minimal 5 karakter');$r=$this->request($id,$companyId);if($r['status']!=='PENDING_APPROVAL')throw new \RuntimeException('Privileged action tidak pending');$now=date('Y-m-d H:i:s');$this->db()->table('privileged_action_requests')->where('id',$id)->where('status','PENDING_APPROVAL')->update(['status'=>'REJECTED','rejected_by'=>$userId,'rejected_at'=>$now,'rejection_reason'=>trim($reason),'updated_at'=>$now]);return ['request_id'=>$id,'status'=>'REJECTED'];}
    /** @return array<string,mixed> */ private function request(int $id,int $companyId):array{$r=$this->db()->table('privileged_action_requests')->where('id',$id)->where('company_id',$companyId)->where('deleted_at IS NULL')->get()->getRowArray();if(!$r)throw new \RuntimeException('Privileged action tidak ditemukan');return $r;}
    private function db():BaseConnection{return $this->connection??Database::connect();}
}
