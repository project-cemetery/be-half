<?php

namespace AppBundle\Utils;


use AppBundle\Entity\Band;
use AppBundle\Entity\Transaction;
use AppBundle\Entity\User;
use AppBundle\Utils\Exception\BandIsFullException;
use AppBundle\Utils\Exception\BandNotExistException;
use AppBundle\Utils\Exception\UserAlreadyInBandException;
use AppBundle\Utils\Exception\UserDoesntHavePartnerException;
use AppBundle\Utils\Exception\UserIsNotInBandException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Telegram\Bot\Api;

class TelegramBot
{
    /* Command Constant */
    const COMMAND_START = '/start';
    const COMMAND_CREATE_BAND = 'создать';
    const COMMAND_LEAVE_BAND = 'покинуть';
    const COMMAND_JOIN_BAND = 'вступить';
    const COMMAND_INFO_BAND = 'моя';
    const COMMAND_STATUS = 'статус';
    const COMMAND_HELP = 'помощь';
    const COMMAND_HISTORY = 'история';
    const COMMAND_NEW_TRANSACTION = 'transaction';

    const DEFAULT_HISTORY_LENGTH = 10;

    /** @var ContainerInterface */
    private $container;

    /** @var Api */
    private $api;

    /** @var LoanManager */
    private $lm;

    public function __construct(ContainerInterface $container, LoanManager $lm)
    {
        $this->container = $container;
        $this->lm = $lm;

        $this->api = new Api($this->container->getParameter('app.telegram_api_key'));
    }

    public function parseTextToCommand($text)
    {
        $text = mb_strtolower($text);

        $separator = " ";

        $words = array();
        $tok = strtok($text, $separator);

        while($tok) {
            $words[] = $tok;
            $tok = strtok(" \t\n");
        }

        if (is_numeric($words[0])) {
            $command = self::COMMAND_NEW_TRANSACTION;
            $params = [
                'amount'  => $words[0],
                'comment' => implode(' ', array_slice($words, 1)),
            ];
        } else {
            $command = $words[0];
            $params = array_slice($words, 1);
        }

        return [$command, $params];
    }

    public function handleCommand($command, array $params, User $user)
    {
        switch ($command) {
            case self::COMMAND_START:
                $this->handleStartCommand($user);
                $this->handleHelpCommand($user);
                break;
            case self::COMMAND_CREATE_BAND:
                $this->handleCreateBandCommand($user);
                break;
            case self::COMMAND_JOIN_BAND:
                $this->handleJoinBandCommand($user, $params);
                break;
            case self::COMMAND_LEAVE_BAND:
                $this->handleLeaveBandCommand($user);
                break;
            case self::COMMAND_INFO_BAND;
                $this->handleInfoBandCommand($user);
                break;
            case self::COMMAND_STATUS:
                $this->handleStatusCommand($user);
                break;
            case self::COMMAND_HELP:
                $this->handleHelpCommand($user);
                break;
            case self::COMMAND_HISTORY:
                $this->handleHistoryCommand($user);
                break;
            case self::COMMAND_NEW_TRANSACTION:
                $this->handleNewTransaction($user, $params);
                break;
        }

        return true;
    }

    private function handleStartCommand(User $user)
    {
        $messages = [];

        $messages[] = "Здравствуйте! Я – be half. Помогу следить за совместными тратами.";

        $this->sendMessagesToUser($user, $messages);

        return true;
    }

    private function handleCreateBandCommand(User $user)
    {
        $messages = [];

        try {
            $band = $this->lm->createBand($user);
            $messages[] = 'Группа создана.';
        } catch (UserAlreadyInBandException $e) {
            $band = $e->getBand();
            $messages[] = $e->getMessage();
        }

        $messages[] = 'Индивидуальный номер – ' . $band->getId() . '.';

        $this->sendMessagesToUser($user, $messages);

        return true;
    }

    private function handleJoinBandCommand(User $user, array $params)
    {
        $messages = [];

        try {
            $band = $this->lm->joinBandById($params[0], $user);
            $messages[] = 'Вы вступили в группу.';

            $partner = $band->getPartner($user);

            $messages[] = 'Я буду помогать вести ваши с ' . $partner->getName() . ' расходы.';

            // TODO: Тут расчет на то, что у партнера тоже бот. Надо это в будущем исправить.
            $partnerMessages = [];
            $partnerMessages[] = 'К группе присодинился ваш друг.';
            $partnerMessages[] = 'Я буду помогать вести ваши с ' . $user->getName() . ' расходы.';

            $this->sendMessagesToUser($partner, $partnerMessages);
        } catch (UserAlreadyInBandException $e) {
            $messages[] = $e->getMessage();
        } catch (BandNotExistException $e) {
            $messages[] = $e->getMessage();
        } catch (BandIsFullException $e) {
            $messages[] = $e->getMessage();
        }

        $this->sendMessagesToUser($user, $messages);

        return true;
    }

    private function handleLeaveBandCommand(User $user)
    {
        $messages = [];

        try {
            $partner = $user->getBand()->getPartner($user);

            $user = $this->lm->leaveGroup($user);
            $messages[] = 'Вы успешно покинули группу.';

            // TODO: Тут расчет на то, что у партнера тоже бот. Надо это в будущем исправить.
            $partnerMessages = [];
            $partnerMessages[] = 'Ваш друг покинул группу';

            $this->sendMessagesToUser($partner, $partnerMessages);
        } catch (UserIsNotInBandException $e) {
            $messages[] = 'Вы не состоите в группе.';
        }

        $this->sendMessagesToUser($user, $messages);

        return true;
    }

    private function handleInfoBandCommand(User $user) {
        $messages = [];

        $band = $user->getBand();
        if ($band) {
            $messages[] = 'Вы состите в группе. Индивидуальный номер – ' . $band->getId() . '.';
            $partner = $band->getPartner($user);
            if ($partner) {
                $messages[] = 'Ваш друг – ' . $partner->getName() . '.';
            } else {
                $messages[] = 'В ней никого кроме вас.';
            }
        } else {
            $messages[] = 'Не могу предоставить информацию. Вы не состоите в группе.';
        }

        $this->sendMessagesToUser($user, $messages);

        return true;
    }

    private function handleHelpCommand(User $user)
    {
        $messages = [];

        $messages[] =
"Доступные команды:

помощь
|| выводит список доступных команд;

создать группу
|| создает группу и выводит инструкции для добавления в нее друга;

вступить #
|| вводит вас в указанную группу, # – индивидуальный номер группы;

покинуть группу
|| исключает вас из текущей группы, уведомляет друга;

моя группа
|| выводит индивидуальный номер и членов группы;

история
|| выводит последние " . self::DEFAULT_HISTORY_LENGTH . " транзакций;

статус
|| выводит состояние счета.";

        $messages[] =
"Для создания транзакции отправьте мне сумму покупки и комментерий (необязательно). Сумма будет разделена на два, результат будет записан на счет вашего друга, он получит уведомление.

Пример:

200 печенье

На счет друга будет записано 100 рублей, он получит уведомление об этом с комментарием 'печенье'.";

        $this->sendMessagesToUser($user, $messages);

        return true;
    }

    private function handleStatusCommand(User $user)
    {
        $messages = [];

        if (!$user->getBand()) {
            $messages[] = 'Вы не состоите в группе, о каком статусе речь?';
        } else {
            $balance = $user->getBalance();

            if ($balance == 0) {
                $messages[] = 'Никто никому ничего не должен.';
            } elseif ($balance > 0) {
                $messages[] = 'Друг должен вам ' . abs($balance) . ' руб.';
            } elseif ($balance < 0) {
                $messages[] = 'Вы должны другу ' . abs($balance) . ' руб.';
            }
        }

        $this->sendMessagesToUser($user, $messages);

        return true;
    }

    private function handleHistoryCommand(User $user)
    {
        $messages = [];

        /** @var Transaction[] $transactions */
        $transactions = array_reverse($this->container
            ->get('doctrine.orm.entity_manager')
            ->getRepository(Transaction::class)
            ->findLastByUser($user, self::DEFAULT_HISTORY_LENGTH));

        foreach ($transactions as $transaction) {
            $message = '';

            if ($transaction->getUser()->getId() == $user->getId()) {
                $message .= '+ ';
            } else {
                $message .= '- ';
            }

            $message .= $transaction->getAmount() . ' руб.';

            if ($transaction->getComment()) {
                $message .= ' (' . $transaction->getComment() . ')';
            }

            $messages[] = $message;
        }

        $this->sendMessagesToUser($user, $messages);

        return true;
    }

    private function handleNewTransaction(User $user, array $params)
    {
        $messages = [];

        try {
            $transaction = $this->lm->createTransaction($user, $params['amount'] / 2, $params['comment']);

            $messages[] = $partnerMessages[] =
"Новая транзакция:
+ " . $transaction->getAmount() . " руб. (" . $transaction->getComment() . ")";

            // TODO: Тут расчет на то, что у партнера тоже бот. Надо это в будущем исправить.
            $partner = $user->getBand()->getPartner($user);

            $partnerMessages = [];
            $partnerMessages[] =
"Новая транзакция:
- " . $transaction->getAmount() . " руб. (" . $transaction->getComment() . ")";

            $this->sendMessagesToUser($partner, $partnerMessages);
        } catch (UserIsNotInBandException $e) {
            $messages[] = $e->getMessage();
        } catch (UserDoesntHavePartnerException $e) {
            $messages[] = $e->getMessage();
        }

        $this->sendMessagesToUser($user, $messages);

        return true;
    }

    private function sendMessagesToUser(User $user, array $messages) {
        foreach ($messages as $message) {
            $this->api->sendMessage([
                'chat_id'      => $user->getTelegramChatId(),
                'text'         => $message,
            ]);
        }
    }
}