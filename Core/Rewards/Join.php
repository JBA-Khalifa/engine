<?php
/**
 * Join the rewards program
 */
namespace Minds\Core\Rewards;

use Minds\Core\Di\Di;
use Minds\Core;
use Minds\Core\Referrals\Referral;
use Minds\Entities\User;
use Minds\Core\Util\BigNumber;

class Join
{

    /** @var TwoFactor $twofactor */
    private $twofactor;

    /** @var Core\SMS\SMSServiceInterface $sms */
    private $sms;

    /** @var PhoneNumberUtil $libphonenumber */
    private $libphonenumber;

    /** @var User $user */
    private $user;

    /** @var int $number */
    private $number;

    /** @var int $code */
    private $code;

    /** @var string $secret */
    private $secret;

    /** @var Config $config */
    private $config;

    /** @var ReferralValidator */
    private $validator;

    /** @var OfacBlacklist */
    private $ofacBlacklist;
    
    /** @var TestnetBalance */
    private $testnetBalance;

    /** @var Call */
    private $db;

    /** @var ReferralDelegate $eventsDelegate */
    private $referralDelegate;

    public function __construct(
        $twofactor = null,
        $sms = null,
        $libphonenumber = null,
        $config = null,
        $validator = null,
        $db = null,
        $joinedValidator = null,
        $ofacBlacklist = null,
        $testnetBalance = null,
        $referralDelegate = null
    )
    {
        $this->twofactor = $twofactor ?: Di::_()->get('Security\TwoFactor');
        $this->sms = $sms ?: Di::_()->get('SMS');
        $this->libphonenumber = $libphonenumber ?: \libphonenumber\PhoneNumberUtil::getInstance();
        $this->config = $config ?: Di::_()->get('Config');
        $this->validator = $validator ?: Di::_()->get('Rewards\ReferralValidator');
        $this->db = $db ?: new Core\Data\Call('entities_by_time');
        $this->joinedValidator = $joinedValidator ?: Di::_()->get('Rewards\JoinedValidator');
        $this->ofacBlacklist = $ofacBlacklist ?: Di::_()->get('Rewards\OfacBlacklist');
        $this->testnetBalance = $testnetBalance ?: Di::_()->get('Blockchain\Wallets\OffChain\TestnetBalance');
        $this->referralDelegate = $referralDelegate ?: new Join\Delegates\ReferralDelegate;
    }

    public function setUser(&$user)
    {
        $this->user = $user;
        return $this;
    }

    public function setNumber($number)
    {
        if ($this->ofacBlacklist->isBlacklisted($number)) {
            throw new \Exception('Because your country is currently listed on the OFAC sanctions list you are unable to earn rewards or purchase tokens');
        }
        $proto = $this->libphonenumber->parse("+$number");
        $this->number = $this->libphonenumber->format($proto, \libphonenumber\PhoneNumberFormat::E164);
        return $this;
    }

    public function setCode($code)
    {
        $this->code = $code;
        return $this;
    }

    public function setSecret($secret)
    {
        $this->secret = $secret;
        return $this;
    }

    public function verify()
    {
        $secret = $this->twofactor->createSecret();
        $code = $this->twofactor->getCode($secret);

        $user_guid = $this->user->guid;
        $this->db->insert("rewards:verificationcode:$user_guid", compact('code', 'secret'));

        if (!$this->sms->verify($this->number)) {
            throw new \Exception('voip phones not allowed');
        }
        $this->sms->send($this->number, $code);

        return $secret;
    }

    public function resendCode()
    {
        $user_guid = $this->user->guid;
        $row = $this->db->getRow("rewards:verificationcode:$user_guid");

        if (!empty($row)) {
            if (!$this->sms->verify($this->number)) {
                throw new \Exception('voip phones not allowed');
            }
            $this->sms->send($this->number, $row['code']);

            return $row['secret'];
        }
    }

    public function confirm()
    {

        if ($this->user->getPhoneNumberHash()) {
            return false; //already joined
        }
 
        if ($this->twofactor->verifyCode($this->secret, $this->code, 8)) {
            $hash = hash('sha256', $this->number . $this->config->get('phone_number_hash_salt'));
            $this->user->setPhoneNumberHash($hash);
            $this->user->save();

            $this->joinedValidator->setHash($hash);
            if ($this->joinedValidator->validate()) {
                $event = new Core\Analytics\Metrics\Event();
                $event->setType('action')
                    ->setProduct('platform')
                    ->setUserGuid((string) $this->user->guid)
                    ->setUserPhoneNumberHash($hash)
                    ->setAction('joined')
                    ->push();

                $this->testnetBalance->setUser($this->user);
                $testnetBalanceVal = BigNumber::_($this->testnetBalance->get());
               
                if ($testnetBalanceVal->lt(0)) { 
                    return false; //balance negative
                }
                
                $transactions = Di::_()->get('Blockchain\Wallets\OffChain\Transactions');
                $transactions
                    ->setUser($this->user)
                    ->setType('joined')
                    ->setAmount((string) $testnetBalanceVal);

                $transaction = $transactions->create();
            }

            // OJMQ: how is this the second half of this if statement working? 
            // OJMQ: i.e. user->guid will never equal user->referrer because referrer is stored as username?
            if ($this->user->referrer && $this->user->guid != $this->user->referrer) {
                $this->validator->setHash($hash);

                if ($this->validator->validate()) {
                    $event = new Core\Analytics\Metrics\Event();
                    $event->setType('action')
                        ->setProduct('platform')
                        ->setUserGuid((string) $this->user->guid)
                        ->setUserPhoneNumberHash($hash)
                        ->setEntityGuid((string) $this->user->referrer)
                        ->setEntityType('user')
                        ->setAction('referral')
                        ->push();

                    // OJMQ: should I move this file into the Join folder that I made to hold the delegate?
                    // OJMQ: and if yes, do I need to change everything that currently points to 'Core/Join' to 'Core/Join/Join'? 

                    $referral = new Referral();
                    // OJMQ: will this setReferrerGuid work?
                    $referral->setReferrerGuid((string) $this->user->referrer)
                        ->setProspectGuid($this->user->guid)
                        ->setJoinTimestamp(time());
                
                    $this->referralDelegate->update($referral);

                }
            }
        } else {
            throw new \Exception('The confirmation failed');
        }

        return true;
    }

}
