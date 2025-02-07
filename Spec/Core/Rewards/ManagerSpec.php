<?php

namespace Spec\Minds\Core\Rewards;

use Brick\Math\BigDecimal;
use Minds\Common\Repository\Response;
use Minds\Core\Blockchain\Token;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

use Minds\Core\Rewards\Contributions\Manager as ContributionsManager;
use Minds\Core\Blockchain\Transactions\Repository as TxRepository;
use Minds\Core\Blockchain\Wallets\OffChain\Transactions;
use Minds\Core\Blockchain\Transactions\Transaction;
use Minds\Core\Blockchain\LiquidityPositions;
use Minds\Core\Blockchain\LiquidityPositions\LiquidityPositionSummary;
use Minds\Core\Blockchain\Services\BlockFinder;
use Minds\Core\Blockchain\Wallets\OnChain\UniqueOnChain;
use Minds\Core\Blockchain\Wallets\OnChain\UniqueOnChain\UniqueOnChainAddress;
use Minds\Core\EntitiesBuilder;
use Minds\Core\Rewards\Contributions\ContributionSummary;
use Minds\Core\Rewards\Repository as RewardsRepository;
use Minds\Core\Rewards\RewardEntry;
use Minds\Entities\User;

class ManagerSpec extends ObjectBehavior
{
    /** @var ContributionsManager */
    private $contributions;

    /** @var Transactions */
    private $transactions;

    /** @var TxRepository */
    private $txRepository;

    /** @var RewardsRepository */
    private $repository;

    /** @var EntitiesBuilder */
    private $entitiesBuilder;

    /** @var LiquidityPositions\Manager */
    private $liquidityPositionManager;

    /** @var UniqueOnChain\Manager */
    private $uniqueOnChainManager;

    /** @var BlockFinder */
    private $blockFinder;

    /** @var Token */
    private $token;

    public function let(
        ContributionsManager $contributions,
        Transactions $transactions,
        TxRepository $txRepository,
        RewardsRepository $repository,
        EntitiesBuilder $entitiesBuilder,
        LiquidityPositions\Manager $liquidityPositionManager,
        UniqueOnChain\Manager $uniqueOnChainManager,
        BlockFinder $blockFinder,
        Token $token
    ) {
        $this->beConstructedWith(
            $contributions,
            $transactions,
            $txRepository,
            null, // $eth
            null, // $config
            $repository, // $repository
            $entitiesBuilder, // $entitiesBuilder
            $liquidityPositionManager, // $liquidityPositionManager
            $uniqueOnChainManager, // $uniqueOnChainManager
            $blockFinder,
            $token, // $token
            null // $logger
        );
        $this->contributions = $contributions;
        $this->transactions = $transactions;
        $this->txRepository = $txRepository;
        $this->repository = $repository;
        $this->entitiesBuilder = $entitiesBuilder;
        $this->liquidityPositionManager = $liquidityPositionManager;
        $this->uniqueOnChainManager = $uniqueOnChainManager;
        $this->blockFinder = $blockFinder;
        $this->token = $token;
    }

    public function it_is_initializable()
    {
        $this->shouldHaveType('Minds\Core\Rewards\Manager');
    }

    public function it_should_add_reward_entry_to_repository()
    {
        $rewardEntry = new RewardEntry();

        $this->repository->add($rewardEntry)
            ->shouldBeCalled();

        $this->add($rewardEntry);
    }

    public function it_should_calculate_rewards()
    {
        // Mock of our users
        $user1 = (new User);
        $user1->guid = '123';
        $user1->setPhoneNumberHash('phone_hash');
        $user1->setEthWallet('0xAddresss');

        $this->entitiesBuilder->single('123')
            ->willReturn($user1);

        // Engagement
        $this->contributions->getSummaries(Argument::any())
            ->shouldBeCalled()
            ->willReturn([
                (new ContributionSummary)
                    ->setUserGuid('123')
                    ->setScore(10)
                    ->setAmount(2),
            ]);
        $this->repository->add(Argument::that(function ($rewardEntry) {
            return $rewardEntry->getUserGuid() === '123'
                && $rewardEntry->getScore()->toFloat() === (float) 10
                && $rewardEntry->getRewardType() === 'engagement';
        }))->shouldBeCalled();

        // Liquidity
        $this->liquidityPositionManager->setDateTs(Argument::any())
                ->willReturn($this->liquidityPositionManager);

        $this->liquidityPositionManager->getAllProvidersSummaries()
                ->willReturn([
                    (new LiquidityPositionSummary())
                        ->setUserGuid('123')
                        ->setUserLiquidityTokens(BigDecimal::of(10)),
                ]);
        // We request yesterdays RewardEntry
        $this->repository->getList(Argument::that(function ($opts) {
            return $opts->getUserGuid() === '123'
                && $opts->getDateTs()  === time() - 86400;
        }))
            ->willReturn(new Response([
                (new RewardEntry())
                    ->setRewardType('liquidity')
                    ->setMultiplier(BigDecimal::of(1)),
                (new RewardEntry())
                    ->setRewardType('holding')
                    ->setMultiplier(BigDecimal::of('1.0054794520540')),
            ]));
        // Add to the repository
        $this->repository->add(Argument::that(function ($rewardEntry) {
            return $rewardEntry->getUserGuid() === '123'
                && (string) $rewardEntry->getScore() === '10.054794520550'
                && $rewardEntry->getRewardType() === 'liquidity';
        }))->shouldBeCalled();

        // Holding
        $this->blockFinder->getBlockByTimestamp(Argument::any())
            ->willReturn(1);

        $this->uniqueOnChainManager->getAll()
            ->willReturn(new Response([
                (new UniqueOnChainAddress)
                    ->setAddress('0xAddresss')
                    ->setUserGuid('123')
            ]));
        
        $this->token->fromTokenUnit("10")
                ->willReturn(10);
        $this->token->balanceOf('0xAddresss', 1)
                ->willReturn("10");

        $this->repository->add(Argument::that(function ($rewardEntry) {
            return $rewardEntry->getUserGuid() === '123'
                && (string) $rewardEntry->getScore() === "10.1095890410900"
                && $rewardEntry->getRewardType() === 'holding';
        }))->shouldBeCalled();

        // Calculation of tokens
        $this->repository->getIterator(Argument::any())
                ->willReturn([
                    (new RewardEntry())
                        ->setUserGuid('123')
                        ->setRewardType('engagement')
                        ->setScore(BigDecimal::of(25))
                        ->setSharePct(0.5),
                ]);
        $this->repository->update(Argument::that(function ($rewardEntry) {
            return $rewardEntry->getUserGuid() === '123'
                && $rewardEntry->getTokenAmount()
                && $rewardEntry->getTokenAmount()->toFloat() === (float) 2000 // 50% of all available rewards in pool
                && $rewardEntry->getRewardType() === 'engagement';
        }), ['token_amount'])->shouldBeCalled()
            ->willReturn(true);

        $this->calculate();
    }

    public function it_should_allow_max_multiplier_of_3_in_365_days()
    {
        $rewardEntry = new RewardEntry();
        $rewardEntry->setMultiplier(BigDecimal::of('1'));

        $i = 0;
        while ($i < 730) {
            ++$i;
            $multiplier = $this->calculateMultiplier($rewardEntry);
            $rewardEntry->setMultiplier($multiplier->getWrappedObject());
        }

        $multiplier->toFloat()->shouldBe((float) 3);
    }

    ////
    // Legacy
    ////

    public function it_should_sync_contributions_to_rewards()
    {
        $from = strtotime('midnight tomorrow -24 hours', time()) * 1000;
        $to = strtotime('midnight tomorrow', time()) * 1000;
        $user = new User;
        $user->guid = 123;

        $this->txRepository->getList([
            'user_guid' => 123,
            'wallet_address' => 'offchain',
            'timestamp' => [
                'gte' => $from,
                'lte' => $to,
                'eq' => null,
            ],
            'contract' => 'offchain:reward',
            ])
            ->shouldBeCalled()
            ->willReturn(null);

        $this->contributions
            ->setFrom($from)
            ->shouldBeCalled()
            ->willReturn($this->contributions);

        $this->contributions
            ->setTo($to)
            ->shouldBeCalled()
            ->willReturn($this->contributions);
        
        $this->contributions
            ->setUser($user)
            ->shouldBeCalled()
            ->willReturn($this->contributions);

        $this->contributions->getRewardsAmount()
            ->shouldBeCalled()
            ->willReturn(20);

        $this->txRepository->add(Argument::that(function ($transaction) {
            return true;
        }))
            ->shouldBeCalled();

        $manager = $this->setUser($user)
            ->setFrom($from)
            ->setTo($to);

        $manager->sync()->getAmount()->shouldBe(20);
        $manager->sync()->getContract()->shouldBe('offchain:reward');
        $manager->sync()->getTimestamp()->shouldBe(strtotime('-1 second', $to / 1000));
    }

    public function it_should_not_allow_duplicate_rewards_to_be_sent()
    {
        $this->txRepository->getList([
            'user_guid' => 123,
            'wallet_address' => 'offchain',
            'timestamp' => [
                'gte' => time() * 1000,
                'lte' => time() * 1000,
                'eq' => null,
            ],
            'contract' => 'offchain:reward',
            ])
            ->shouldBeCalled()
            ->willReturn([
                'transactions' => [(new Transaction)]
            ]);

        $user = new User;
        $user->guid = 123;
        $manager = $this->setUser($user)
            ->setFrom(time() * 1000)
            ->setTo(time() * 1000);

        $manager->shouldThrow('\Exception')->duringSync();
    }
}
