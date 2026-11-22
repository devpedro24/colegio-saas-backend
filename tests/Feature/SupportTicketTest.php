<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\SupportMessage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::create(['name'=>'GirGit team', 'email'=>'support@tests.test','password'=>'Password!123',
            'role'=>'superadmin','status'=>'active','must_change_password'=>false]);
        $this->withToken($user->createToken('platform')->plainTextToken)->withHeader('Accept','application/json');
        return $user;
    }

    private function ticket(string $suffix='one'): SupportTicket
    {
        $school=Tenant::withoutEvents(fn()=>Tenant::create(['id'=>'support-'.$suffix,'slug'=>'support-'.$suffix,
            'name'=>'School '.$suffix,'plan'=>'estandar','status'=>'active']));
        $ticket=SupportTicket::create(['url_token'=>str_pad($suffix,24,'x'),'reference'=>'GG-'.strtoupper($suffix),
            'tenant_id'=>$school->id,'school_name'=>$school->name,'origin_name'=>$school->name,
            'requester_id'=>42,'requester_name'=>'Rector','request_key'=>str_repeat('c',24),
            'subject'=>'Cannot save a bulletin','category'=>'academico','status'=>'abierto','priority'=>false,'version'=>1]);
        SupportMessage::create(['ticket_id'=>$ticket->id,'tenant_id'=>$school->id,'request_key'=>str_repeat('d',24),
            'author_side'=>'colegio','author_name'=>'Rector','author_id'=>42,'body'=>'Initial support request.']);
        return $ticket;
    }

    public function test_platform_inbox_and_conversation_use_public_selectors_and_pagination(): void
    {
        $this->admin(); $ticket=$this->ticket();
        $this->getJson('/api/platform/support/tickets')->assertOk()->assertJsonCount(1,'data')
            ->assertJsonMissingPath('data.0.id')->assertJsonMissingPath('data.0.tenant_id')->assertJsonMissingPath('data.0.requester_id');
        $base='/api/platform/support/tickets/'.$ticket->url_token;
        $this->getJson($base)->assertOk()->assertJsonPath('messages.0.body','Initial support request.')
            ->assertJsonMissingPath('messages.0.author_id')->assertJsonMissingPath('data.request_key');
        $this->getJson('/api/platform/support/tickets/'.$ticket->id)->assertNotFound();
        $this->getJson('/api/platform/support/tickets/'.str_repeat('z',24))->assertNotFound();
        $this->getJson('/api/platform/support/tickets?q=School')->assertJsonCount(1,'data');
        $this->getJson('/api/platform/support/tickets?q=%')->assertJsonCount(0,'data');
        $this->getJson('/api/platform/support/tickets?tenant_id=other')->assertUnprocessable();
    }

    public function test_replies_are_idempotent_and_closed_tickets_require_reopening(): void
    {
        $this->admin(); $ticket=$this->ticket();
        $base='/api/platform/support/tickets/'.$ticket->url_token;
        $message=['body'=>'<script>literal support text</script>','request_key'=>str_repeat('a',24),'author_side'=>'colegio','author_name'=>'forged'];
        $this->postJson($base.'/messages',$message)->assertOk();
        $this->postJson($base.'/messages',$message)->assertOk();
        $this->assertSame(2,SupportMessage::count());
        $this->getJson($base)->assertJsonPath('messages.1.side','plataforma')->assertJsonPath('messages.1.author','GirGit team');
        $this->postJson($base.'/messages',[...$message,'body'=>'Different retry'])->assertConflict();
        $version=$ticket->fresh()->version;
        $this->putJson($base.'/state',['status'=>'cerrado','version'=>$version])->assertOk();
        $this->putJson($base.'/state',['status'=>'abierto','version'=>$version])->assertConflict();
        $this->postJson($base.'/messages',['body'=>'New message','request_key'=>str_repeat('b',24)])->assertConflict();
        $this->putJson($base.'/state',['status'=>'abierto','version'=>$ticket->fresh()->version])->assertOk();
        $this->postJson($base.'/messages',['body'=>'New message','request_key'=>str_repeat('b',24)])->assertOk();
    }

    public function test_platform_permissions_validation_and_state_filters(): void
    {
        $admin=$this->admin(); $ticket=$this->ticket(); $base='/api/platform/support/tickets/'.$ticket->url_token;
        $this->getJson('/api/platform/support/tickets?status=missing')->assertUnprocessable();
        $this->getJson('/api/platform/support/tickets?status=resuelto')->assertJsonCount(0,'data');
        $this->postJson($base.'/messages',['body'=>'x','request_key'=>str_repeat('f',24)])->assertUnprocessable();
        $this->putJson($base.'/state',['status'=>'missing','version'=>1])->assertUnprocessable();
        $admin->update(['role'=>'docente']); app('auth')->forgetGuards();
        $this->getJson('/api/platform/support/tickets')->assertForbidden();
        $this->flushHeaders(); app('auth')->forgetGuards();
        $this->getJson('/api/platform/support/tickets')->assertUnauthorized();
    }
}
