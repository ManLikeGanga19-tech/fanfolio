<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;

// Canvas boards MarketingHome and MarketingCreators.

it('shows the home page to guests with every section of the board', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSeeInOrder([
            'Made for African creators',
            'Your money.',
            'ID-verified adult creators',
            'Get closer to the creators you love',
            'Keep the biggest share of what you earn',
            '80%',
            'Price your work the way you want',
            'Built to protect creators and fans',
            'Something for every fan',
            'Questions, answered',
            'Start building income from the people who already love your work',
        ])
        ->assertSee(e(route('signup', ['as' => 'creator'])), false)
        ->assertSee(route('marketing.creators'), false)
        // Open decision: the refund policy stays a placeholder (CLAUDE.md).
        ->assertSee('[CREDIT REFUND POLICY]')
        // Mock figures from the board are never built in.
        ->assertDontSee('1,284.40')
        ->assertDontSee('Sample data');
});

it('shows For creators with the worked sale calculated from the money rules', function (): void {
    $this->get('/creators')
        ->assertOk()
        ->assertSeeInOrder(['Turn your following into income', 'You keep 80%. Always.', 'Live in four steps', 'Everything you need to run your page', 'Withdraw the way that works for you', 'Your content, guarded', 'Before you start'])
        ->assertSeeInOrder(['1,000 credits · $10.00', '− $2.00', 'Clears after 7 days', '$8.00'])
        ->assertSee('No website can stop every screen capture.');

    // The example follows the configured fee, so it cannot drift from the real rule.
    config(['fanfolio.money.platform_fee_bps' => 2500]);
    $this->get('/creators')->assertSeeInOrder(['− $2.50', '$7.50'])->assertSee('You keep 75%');
});

it('opens For creators to members too, and sends members from the home page to their account', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/creators')->assertOk()->assertSee('Account');
    $this->get('/')->assertRedirect(route('account'));
});
