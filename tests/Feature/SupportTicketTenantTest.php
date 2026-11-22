<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\SupportTicket;
use App\Models\SupportMessage;
use App\Services\TenantProvisioner;
use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

class SupportTicketTenantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $database=(string)config('database.connections.pgsql.database');
        if(getenv('SUPPORT_POSTGRES_TEST')!=='1'||!preg_match('/^codex_support_test_[a-f0-9]{10}$/D',$database)) {
            $this->markTestSkipped('Run scripts/test-support-postgres.php for tenant integration.');
        }
        $this->artisan('migrate:fresh',['--database'=>'pgsql','--force'=>true])->assertExitCode(0);
        \Stancl\Tenancy\DatabaseConfig::generateDatabaseNamesUsing(fn($tenant)=>$database.'_t_'.substr(hash('sha256',$tenant->getKey()),0,10));
        $this->seed(DatabaseSeeder::class);
        $this->withHeader('Accept','application/json');
    }
    protected function tearDown(): void
    {
        if(tenancy()->initialized)tenancy()->end();
        parent::tearDown();
    }
    private function school(string $slug): array
    {
        ['tenant'=>$school]=app(TenantProvisioner::class)->provision(['name'=>$slug,'slug'=>$slug,'rector_email'=>'rector@'.$slug.'.test']);
        $school->update(['status'=>Tenant::STATUS_ACTIVE]);
        $token=$school->run(function(){
            $user=User::where('role','rector')->firstOrFail();$user->update(['must_change_password'=>false]);
            return $user->createToken('web')->plainTextToken;
        });
        return [$school,$token];
    }
    private function token(string $token): void { $this->withToken($token);app('auth')->forgetGuards(); }

    public function test_school_ticket_lifecycle_is_isolated_and_platform_team_can_reply(): void
    {
        $admin=User::create(['name'=>'GirGit team','email'=>'support@tests.test','password'=>'Password!123','role'=>'superadmin','status'=>'active','must_change_password'=>false]);
        $adminToken=$admin->createToken('platform')->plainTextToken;
        [$first,$rector]=$this->school('first-support');[$second,$other]=$this->school('second-support');
        $base='http://'.$first->slug.'.localhost/api/support/tickets';
        $otherBase='http://'.$second->slug.'.localhost/api/support/tickets';
        $data=['subject'=>'Cannot save the report','category'=>'tecnico','body'=>'The report does not save when I click continue.','request_key'=>str_repeat('a',24),'priority'=>true,'status'=>'resuelto'];
        $this->token($rector);
        $saved=$this->postJson($base,$data)->assertCreated()->assertJsonPath('data.priority',false)->assertJsonPath('data.status','abierto')
            ->assertJsonMissingPath('data.id')->assertJsonMissingPath('data.tenant_id');
        $this->postJson($base,$data)->assertCreated();
        $this->assertSame(1,SupportTicket::count());$this->assertSame(1,SupportMessage::count());
        $token=$saved->json('data.token');
        $this->postJson($base,[...$data,'request_key'=>str_repeat('b',24),'tenant_id'=>$second->id])->assertUnprocessable();
        $this->getJson($base.'/'.$token)->assertOk()->assertJsonPath('messages.0.side','colegio');
        $this->putJson($base.'/'.$token.'/state',['status'=>'en_proceso','version'=>1])->assertForbidden();
        $this->token($other);
        $this->getJson($otherBase)->assertOk()->assertJsonCount(0,'data');
        $this->getJson($otherBase.'/'.$token)->assertNotFound();
        $this->postJson($otherBase.'/'.$token.'/messages',['body'=>'Forbidden reply','request_key'=>str_repeat('f',24)])->assertNotFound();
        $this->putJson($otherBase.'/'.$token.'/state',['status'=>'cerrado','version'=>1])->assertNotFound();
        $teacher=$first->run(function(){
            $user=User::create(['name'=>'Teacher','email'=>'teacher@support.test','password'=>'Password!123','role'=>'docente','status'=>'active','must_change_password'=>false]);
            $user->assignRole('docente');return $user->createToken('web')->plainTextToken;
        });
        $this->token($teacher);$this->getJson($base)->assertForbidden();$this->postJson($base,$data)->assertForbidden();
        if(tenancy()->initialized)tenancy()->end();
        $this->token($adminToken);
        $platform='http://localhost/api/platform/support/tickets';
        $this->getJson($platform)->assertOk()->assertJsonCount(1,'data');
        $this->postJson($platform.'/'.$token.'/messages',['body'=>'We are reviewing your report.','request_key'=>str_repeat('c',24)])->assertOk();
        $version=SupportTicket::first()->version;
        $this->putJson($platform.'/'.$token.'/state',['status'=>'resuelto','version'=>$version])->assertOk();
        $this->token($rector);
        $this->getJson($base.'/'.$token)->assertOk()->assertJsonPath('messages.1.side','plataforma')->assertJsonPath('data.status','resuelto');
        $this->postJson($base.'/'.$token.'/messages',['body'=>'This still occurs.','request_key'=>str_repeat('d',24)])->assertOk();
        $detail=$this->getJson($base.'/'.$token)->assertJsonPath('data.status','abierto');
        $this->putJson($base.'/'.$token.'/state',['status'=>'cerrado','version'=>$detail->json('data.version')])->assertOk();
        $detail=$this->getJson($base.'/'.$token);
        $this->putJson($base.'/'.$token.'/state',['status'=>'abierto','version'=>$detail->json('data.version')])->assertOk();
        $first->update(['plan'=>'premium','commercial'=>null]);
        // Simulate the next HTTP request resolving the school afresh after a plan change.
        if(tenancy()->initialized)tenancy()->end();
        $this->token($rector);
        $this->postJson($base,[...$data,'request_key'=>str_repeat('e',24)])->assertCreated()->assertJsonPath('data.priority',true);
    }
}
