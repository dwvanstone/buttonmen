<?php
/**
 * BMGameAction: Record of an action which happened during a game
 *
 * @author chaos
 */

/**
 * This class allows game actions at each game state to be logged
 *
 * @property     int    $gameState           BMGameState of the game when the action occurred
 * @property     string $actionType          Type of action which was taken
 * @property     int    $actingPlayerId      Database ID of player who took the action
 * @property     array  $params              Array of information about the action, format depends on actionType
 */
class BMGameAction {

    /**
     * BMGameState of the game when the action occurred
     *
     * @var int
     */
    private $gameState;

    /**
     * Type of action which was taken
     *
     * @var string
     */
    private $actionType;

    /**
     * Database ID of player who took the action
     *
     * @var int
     */
    private $actingPlayerId;

    /**
     * Array of information about the action, format depends on actionType
     *
     * @var array
     */
    private $params;

    /**
     * Constructor
     *
     * @param int $gameState
     * @param string $actionType
     * @param int $actingPlayerId
     * @param array $params
     */
    public function __construct(
        $gameState,
        $actionType,
        $actingPlayerId,
        $params
    ) {
        if (!$params) {
            throw new Exception("BMGameAction error: params can't be empty");
        }
        $this->gameState = $gameState;
        $this->actionType = $actionType;
        $this->actingPlayerId = $actingPlayerId;
        $this->params = $params;
    }

    /**
     * Creates human-readable action log message
     *
     * This function is the main function, which calls subfunctions
     * friendly_message_*() as required
     *
     * @param array $playerIdNames
     * @param int $roundNumber
     * @param int $gameState
     * @return string
     */
    public function friendly_message($playerIdNames, $roundNumber, $gameState) {
        $this->outputPlayerIdNames = $playerIdNames;
        $this->outputRoundNumber = $roundNumber;
        $this->outputGameState = $gameState;
        if (is_array($this->params)) {
            $funcName = 'friendly_message_' . $this->actionType;
            if (method_exists($this, $funcName)) {
                $result = $this->$funcName();
            } else {
                $result = "Internal error: could not print action log entry of type: "
                          . $this->actionType;
            }
            return $result;

        } else {
            // Messages should now be arrays, but some old string
            // messages might still be in the DB.  Use the old logic for these
            if ($this->actionType == 'attack') {
                return $playerIdNames[$this->actingPlayerId] . ' ' . $this->params;
            }
            if ($this->actionType == 'end_winner') {
                return ('End of round: ' . $playerIdNames[$this->actingPlayerId] . ' ' . $this->params);
            }
            return($this->params);
        }
    }

    /**
     * Describes a draw at the end of a round
     *
     * @return string
     */
    protected function friendly_message_end_draw() {
        $message = 'Round ' . $this->params['roundNumber'] .
                   ' ended in a draw (' .
                   $this->params['roundScoreArray'][0] . ' vs. ' .
                   $this->params['roundScoreArray'][1] . ')';
        return $message;
    }

    /**
     * Describes the end of a round when there is a winner
     *
     * @return string
     */
    protected function friendly_message_end_winner() {
        $message = 'End of round: ' . $this->outputPlayerIdNames[$this->actingPlayerId] .
                   ' won round ' . $this->params['roundNumber'];
        if (array_key_exists('resultForced', $this->params) && ($this->params['resultForced'])) {
            $message .= ' because opponent surrendered';
        } else {
            $message .= ' (' .  max($this->params['roundScoreArray']) . ' vs. ' .
                        min($this->params['roundScoreArray']) . ')';
        }
        return $message;
    }

    /**
     * Describes the situation when fire dice need to be turned down
     *
     * @return string
     */
    protected function friendly_message_needs_firing() {
        $attackType = $this->params['attackType'];
        $attackDice = $this->params['attackDice'];

        $actingPlayerName = $this->outputPlayerIdNames[$this->actingPlayerId];

        $message = $actingPlayerName . ' chose to perform a ' . $attackType . ' attack';

        $attackers = array();
        $defenders = array();
        foreach ($attackDice['attacker'] as $attackerInfo) {
            $attackers[] = $attackerInfo['recipeStatus'];
        }
        foreach ($attackDice['defender'] as $defenderInfo) {
            $defenders[] = $defenderInfo['recipeStatus'];
        }
        $message .= $this->preAttackMessage($attackers, $defenders) . '; ';
        $message .= $actingPlayerName . ' must turn down fire dice to complete this attack';

        return $message;
    }

    /**
     * Describes the situation when a player abandons a attack that needs firing
     *
     * @return string
     */
    protected function friendly_message_fire_cancel() {
        return $this->outputPlayerIdNames[$this->actingPlayerId] .
               ' chose to abandon this attack and start over';
    }

    /**
     * Describes an attack
     *
     * @return string
     */
    protected function friendly_message_attack() {
        $attackType = $this->params['attackType'];
        $preAttackDice = $this->params['preAttackDice'];
        $postAttackDice = $this->params['postAttackDice'];
        $actingPlayerName = $this->outputPlayerIdNames[$this->actingPlayerId];

        // Check for any attack types in which the defender changes
        // in some way we want to report prior to being captured
        if ($attackType == 'Trip') {
            $defenderRerollsEarly = TRUE;
        } else {
            $defenderRerollsEarly = FALSE;
        }

        // First, what type of attack was this?
        if ($attackType == 'Pass') {
            return $actingPlayerName . ' passed';
        }

        if ($attackType == 'Surrender') {
            return $actingPlayerName . ' surrendered';
        }

        $message = '';

        if (empty($this->params['fireCache'])) {
            $message .= $actingPlayerName . ' performed ' . $attackType . ' attack';

            // Add the pre-attack status of all participating dice
            $preAttackAttackers = array();
            $preAttackDefenders = array();
            foreach ($preAttackDice['attacker'] as $attackerInfo) {
                $preAttackAttackers[] = $attackerInfo['recipeStatus'];
            }
            foreach ($preAttackDice['defender'] as $defenderInfo) {
                $preAttackDefenders[] = $defenderInfo['recipeStatus'];
            }
            $message .= $this->preAttackMessage($preAttackAttackers, $preAttackDefenders) . '; ';
        } else {
            $message .= $this->fire_turndown_message($this->params['fireCache'], $actingPlayerName);
        }

        $messageDefender = $this->messageDefender($preAttackDice, $postAttackDice, $defenderRerollsEarly);

        if ($defenderRerollsEarly) {
            // this only triggers for trip attacks, so there can only be one attacker involved
            $midAttackDice = $preAttackDice;

            if (isset($postAttackDice['attacker'][0]['valueAfterTripAttack'])) {
                $midAttackDice['attacker'][0]['value'] =
                    $postAttackDice['attacker'][0]['valueAfterTripAttack'];
            }

            $message .= $this->messageAttacker($preAttackDice, $midAttackDice);
            $message .= '; ' . $messageDefender;

            $splittingAfterTrip = (count($midAttackDice['attacker']) !=
                                   count($postAttackDice['attacker']));
            $morphingAfterTrip = (isset($postAttackDice['attacker'][0]['hasJustMorphed']) &&
                                 ($postAttackDice['attacker'][0]['hasJustMorphed']));

            // deal with splitting after trip
            if ($splittingAfterTrip || $morphingAfterTrip) {
                $message .= '; ' . $this->messageAttacker($midAttackDice, $postAttackDice);
            }
        } else {
            $messageAttacker = $this->messageAttacker($preAttackDice, $postAttackDice);
            $message .= $messageDefender . '; ' . $messageAttacker;
        }

        return $message;
    }

    /**
     * Describes how fire dice have been turned down
     *
     * @param array $fireCache
     * @param string $actingPlayerName
     * @return string
     */
    protected function fire_turndown_message($fireCache, $actingPlayerName) {
        $fireRecipes = $fireCache['fireRecipes'];
        $oldValues = $fireCache['oldValues'];
        $newValues = $fireCache['newValues'];

        $message = $actingPlayerName . ' turned down fire dice: ';
        $messageArray = array();

        foreach ($fireRecipes as $dieIdx => $recipe) {
            $oldValue = $oldValues[$dieIdx];
            $newValue = $newValues[$dieIdx];
            if ($oldValue == $newValue) {
                continue;
            }

            $messageArray[] = $recipe . ' from ' . $oldValue . ' to ' . $newValue;
        }

        $message .= implode(', ', $messageArray) . '; ';

        return $message;
    }

    /**
     * Describes the attackers and defenders at the beginning of an attack
     *
     * @param array $preAttackAttackers
     * @param array $preAttackDefenders
     * @return string
     */
    protected function preAttackMessage($preAttackAttackers, $preAttackDefenders) {
        $message = '';

        if (count($preAttackAttackers) > 0) {
            $message .= ' using [' . implode(",", $preAttackAttackers) . ']';
        }

        if (count($preAttackDefenders) > 0) {
            $message .= ' against [' . implode(",", $preAttackDefenders) . ']';
        }

        return $message;
    }

    /**
     * Describes what happens to each defending die
     *
     * @param array $preAttackDice
     * @param array $postAttackDice
     * @param bool $defenderRerollsEarly
     * @return string
     */
    protected function messageDefender($preAttackDice, $postAttackDice, $defenderRerollsEarly) {
        $messageDefenderArray = array();
        // Report what happened to each defending die
        foreach ($preAttackDice['defender'] as $idx => $defenderInfo) {
            $postInfo = $postAttackDice['defender'][$idx];
            $postEventsDefender = array();

            $this->message_append(
                $postEventsDefender,
                $this->message_recipe_change($defenderInfo, $postInfo, FALSE)
            );

            if ($defenderRerollsEarly || !$postInfo['captured']) {
                $this->message_append(
                    $postEventsDefender,
                    $this->message_value_change($defenderInfo, $postInfo)
                );
            }
            $this->message_append(
                $postEventsDefender,
                $this->message_capture($postInfo)
            );
            $this->message_append(
                $postEventsDefender,
                $this->message_out_of_play($defenderInfo, $postInfo)
            );

            $messageDefenderArray[] = 'Defender ' . $defenderInfo['recipe'] . ' ' . implode(', ', $postEventsDefender);
        }

        $messageDefender = implode('; ', $messageDefenderArray);

        return $messageDefender;
    }

    /**
     * Describes what happens to each attacking die
     *
     * @param array $preAttackDice
     * @param array $postAttackDice
     * @return string
     */
    protected function messageAttacker($preAttackDice, $postAttackDice) {
        $messageAttackerArray = array();

        // deal with the possibility of an attacker that splits
        if (count($preAttackDice['attacker']) <
            count($postAttackDice['attacker'])) {
            return $this->message_split(
                $preAttackDice['attacker'],
                $postAttackDice['attacker']
            );
        }

        // Report what happened to each attacking die
        foreach ($preAttackDice['attacker'] as $idx => $attackerInfo) {
            $postInfo = $postAttackDice['attacker'][$idx];
            $postEventsAttacker = array();

            $this->message_append(
                $postEventsAttacker,
                $this->message_size_change($attackerInfo, $postInfo)
            );
            $this->message_append(
                $postEventsAttacker,
                $this->message_recipe_change($attackerInfo, $postInfo)
            );
            $this->message_append(
                $postEventsAttacker,
                $this->message_value_change($attackerInfo, $postInfo)
            );
            $this->message_append(
                $postEventsAttacker,
                $this->message_out_of_play($attackerInfo, $postInfo)
            );

            if (!empty($postEventsAttacker)) {
                $messageAttackerArray[] =
                    'Attacker ' . $attackerInfo['recipe'] . ' ' . implode(', ', $postEventsAttacker);
            }
        }

        $messageAttacker = implode('; ', $messageAttackerArray);

        return $messageAttacker;
    }

    /**
     * Describes what happens when an attacker splits into two
     *
     * @param array $preAttackAttackers
     * @param array $postAttackAttackers
     * @return string
     */
    protected function message_split(array $preAttackAttackers, array $postAttackAttackers) {
        assert(count($preAttackAttackers) < count($postAttackAttackers));

        // james: currently, the logic only handles one die splitting into two
        assert(1 == count($preAttackAttackers));
        assert(2 == count($postAttackAttackers));

        $messageChangePreSplit = '';
        if (array_key_exists('recipeBeforeSplitting', $postAttackAttackers[0]) &&
            ($preAttackAttackers[0]['recipe'] != $postAttackAttackers[0]['recipeBeforeSplitting'])) {
            $messageChangePreSplit = ' changed to ' .
                                     $postAttackAttackers[0]['recipeBeforeSplitting'] .
                                     ', which then';
        }

        $messagePreSplit = 'Attacker ' . $preAttackAttackers[0]['recipe'] . ' showing ' .
                           $preAttackAttackers[0]['value'] .
                           $messageChangePreSplit .
                           ' split into: ';

        $messagePostSplit0 = $this->message_grow_shrink($postAttackAttackers[0]) .
                             $postAttackAttackers[0]['recipe'] . ' showing ' .
                             $postAttackAttackers[0]['value'];

        $messagePostSplit1 = $this->message_grow_shrink($postAttackAttackers[1]) .
                             $postAttackAttackers[1]['recipe'] . ' showing ' .
                             $postAttackAttackers[1]['value'];

        $message = $messagePreSplit . $messagePostSplit0 . ', and ' . $messagePostSplit1;

        return $message;
    }

    /**
     * Describes a die growing or shrinking
     *
     * @param array $diePropertyArray
     * @return string
     */
    protected function message_grow_shrink(array $diePropertyArray) {
        if (array_key_exists('recipeBeforeGrowing', $diePropertyArray) &&
            $diePropertyArray['recipeBeforeGrowing']) {
            return $diePropertyArray['recipeBeforeGrowing'] . ' which grew into ';
        } elseif (array_key_exists('recipeBeforeShrinking', $diePropertyArray) &&
                  $diePropertyArray['recipeBeforeShrinking']) {
            return $diePropertyArray['recipeBeforeShrinking'] . ' which shrunk into ';
        } else {
            return '';
        }
    }

    /**
     * Appends a message to an array of messages
     *
     * @param array $messageArray
     * @param string $messageIncrement
     */
    protected function message_append(array &$messageArray, $messageIncrement) {
        if (!empty($messageIncrement)) {
            $messageArray[] = $messageIncrement;
        }
    }

    /**
     * Describes a die size change
     *
     * @param array $preInfo
     * @param array $postInfo
     * @return string
     */
    protected function message_size_change($preInfo, $postInfo) {
        $message = '';

        if ($preInfo['max'] != $postInfo['max']) {
            $message = 'changed size from ' . $preInfo['max'] . ' to ' . $postInfo['max'] . ' sides';
        } elseif ((array_key_exists('forceReportDieSize', $preInfo) &&
                   $preInfo['forceReportDieSize']) ||
                  (array_key_exists('forceReportDieSize', $postInfo) &&
                   $postInfo['forceReportDieSize'])) {
            $message = 'remained the same size';
        }

        return $message;
    }

    /**
     * Describes a die recipe change
     *
     * @param array $preInfo
     * @param array $postInfo
     * @param bool $showFrom
     * @return string
     */
    protected function message_recipe_change($preInfo, $postInfo, $showFrom = TRUE) {
        $message = '';

        if ($preInfo['recipe'] != $postInfo['recipe']) {
            $message = 'recipe changed';

            if ($showFrom) {
                $message .= ' from ' . $preInfo['recipe'];
            }

            $message .= ' to ' . $postInfo['recipe'];
        }

        return $message;
    }

    /**
     * Describes a die value change or if the die doesn't reroll
     *
     * @param array $preInfo
     * @param array $postInfo
     * @return string
     */
    protected function message_value_change($preInfo, $postInfo) {
        if ($postInfo['doesReroll']) {
            $message = 'rerolled ' . $preInfo['value'] . ' => ' . $postInfo['value'];
        } else {
            $message = 'does not reroll';
        }

        return $message;
    }

    /**
     * Describes whether the defender was captured
     *
     * @param array $postInfo
     * @return string
     */
    protected function message_capture($postInfo) {
        if ($postInfo['captured']) {
            $message = 'was captured';
        } else {
            $message = 'was not captured';
        }

        return $message;
    }

    /**
     * Describes whether a die has been taken out of play
     *
     * @param array $preInfo
     * @param array $postInfo
     * @return string
     */
    protected function message_out_of_play($preInfo, $postInfo) {
        $message = '';

        $isOutOfPlayPost = array_key_exists('outOfPlay', $postInfo) &&
                           $postInfo['outOfPlay'];
        $isOutOfPlayPre  = array_key_exists('outOfPlay', $preInfo) &&
                           $preInfo['outOfPlay'];

        if ($isOutOfPlayPost && !$isOutOfPlayPre) {
            $message = 'was taken out of play';
        }

        return $message;
    }

    /**
     * Describes choice of option or swing values
     *
     * @return string
     */
    protected function friendly_message_choose_die_values() {
        $message = $this->outputPlayerIdNames[$this->actingPlayerId] . ' set';

        // If the round is later than the one in which this action
        // log entry was recorded, or we're no longer in swing selection
        // state, report the values which were chosen as well
        if (($this->outputRoundNumber != $this->params['roundNumber']) ||
            ($this->outputGameState != BMGameState::SPECIFY_DICE)) {
            $dieMessages = array();
            if (count($this->params['swingValues']) > 0) {
                $swingStrs = array();
                foreach ($this->params['swingValues'] as $swingType => $swingValue) {
                    $swingStrs[] = $swingType . '=' . $swingValue;
                }
                $dieMessages[] = 'swing values: ' . implode(", ", $swingStrs);
            }
            if (count($this->params['optionValues']) > 0) {
                $optionStrs = array();
                foreach ($this->params['optionValues'] as $dieRecipe => $optionValue) {
                    $optionStrs[] = str_replace(')', '=' . $optionValue . ')', $dieRecipe);
                }
                $dieMessages[] = 'option dice: ' . implode(", ", $optionStrs);
            }
            $message .= ' ' . implode(" and ", $dieMessages);
        } else {
            $message .= ' die sizes';
        }
        return $message;
    }

    /**
     * Describes choice of swing values
     *
     * Since the addition of option dice, new choose_swing log
     * entries are no longer added to the DB.  However, this code
     * must be retained to parse old log entries until/unless those
     * are converted.
     *
     * @return string
     */
    protected function friendly_message_choose_swing() {
        $message = $this->outputPlayerIdNames[$this->actingPlayerId] . ' set swing values';

        // If the round is later than the one in which this action
        // log entry was recorded, or we're no longer in swing selection
        // state, report the values which were chosen as well
        if (($this->outputRoundNumber != $this->params['roundNumber']) ||
            ($this->outputGameState != BMGameState::SPECIFY_DICE)) {
            $swingStrs = array();
            foreach ($this->params['swingValues'] as $swingType => $swingValue) {
                $swingStrs[] = $swingType . '=' . $swingValue;
            }
            $message .= ': ' . implode(", ", $swingStrs);
        }
        return $message;
    }

    /**
     * Describes the effect of rerolling a chance die
     *
     * @return string
     */
    protected function friendly_message_reroll_chance() {
        $message = $this->outputPlayerIdNames[$this->actingPlayerId] . ' rerolled a chance die';
        if ($this->params['gainedInitiative']) {
            $message .= ' and gained initiative';
        } else {
            $message .= ', but did not gain initiative';
        }
        $message .= ': ' . $this->params['preReroll']['recipe'] . ' rerolled ' .
                    $this->params['preReroll']['value'] . ' => ' . $this->params['postReroll']['value'];
        return $message;
    }

    /**
     * Describes the effect of turning down focus dice
     *
     * @return string
     */
    protected function friendly_message_turndown_focus() {
        $message = $this->outputPlayerIdNames[$this->actingPlayerId] . ' gained initiative by turning down focus dice';
        $focusStrs = array();
        foreach ($this->params['preTurndown'] as $idx => $die) {
            $focusStrs[] = $die['recipe'] . ' from ' . $die['value'] . ' to ' .
                           $this->params['postTurndown'][$idx]['value'];
        }
        $message .= ': ' . implode(", ", $focusStrs);
        return $message;
    }

    /**
     * Describes the action of declining an initiative action
     *
     * @return string
     */
    protected function friendly_message_init_decline() {
        $message = $this->outputPlayerIdNames[$this->actingPlayerId] .
                   ' chose not to try to gain initiative using chance or focus dice';
        return $message;
    }

    /**
     * Describes the action of adding a reserve die
     *
     * @return string
     */
    protected function friendly_message_add_reserve() {
        $message = $this->outputPlayerIdNames[$this->actingPlayerId] .
                   ' added a reserve die: ' . $this->params['die']['recipe'];
        return $message;
    }

    /**
     * Describes the action of declining extra reserve dice
     *
     * @return string
     */
    protected function friendly_message_decline_reserve() {
        $message = $this->outputPlayerIdNames[$this->actingPlayerId] .
                   ' chose not to add a reserve die';
        return $message;
    }

    /**
     * Describes the action of choosing to add auxiliary dice
     *
     * @return string
     */
    protected function friendly_message_add_auxiliary() {
        // If the round is later than the one in which this action
        // log entry was recorded, or we're no longer in auxiliary selection
        // state, report the action
        if (($this->outputRoundNumber != $this->params['roundNumber']) ||
            ($this->outputGameState != BMGameState::CHOOSE_AUXILIARY_DICE)) {
            $message = $this->outputPlayerIdNames[$this->actingPlayerId] .
                       ' chose to use auxiliary die ' . $this->params['die']['recipe'] .
                       ' in this game';
        } else {
            // Otherwise, return nothing - the fact that this player has made a choice
            // leaks information, so suppress the log entry entirely for now.
            $message = '';
        }
        return $message;
    }

    /**
     * Describes the action of declining auxiliary dice
     *
     * @return string
     */
    protected function friendly_message_decline_auxiliary() {
        $message = $this->outputPlayerIdNames[$this->actingPlayerId] .
                   ' chose not to use auxiliary dice in this game: ' .
                   'neither player will get an auxiliary die';
        return $message;
    }

    /**
     * Describes initiative result
     *
     * @return string
     */
    protected function friendly_message_determine_initiative() {
        $messageArray = array();

        // Summary first: who won initiative
        $messageArray[] = $this->outputPlayerIdNames[$this->params['initiativeWinnerId']] .
                          ' won initiative for round ' . $this->params['roundNumber'];

        // Now report all the initial die rolls without commentary
        $dieRollStrs = array();
        $slowButtonPlayers = array();
        $slowDice = array();
        foreach ($this->params['playerData'] as $playerId => $playerData) {
            $dieVals = array();
            $slowDice[$playerId] = array();
            foreach ($playerData['initiativeDice'] as $initDie) {
                $dieVals[] = $initDie['recipeStatus'];
                if (!$initDie['included']) {
                    $slowDice[$playerId][] = $initDie['recipe'];
                }
            }
            $dieRollStrs[] = $this->outputPlayerIdNames[$playerId] . ' rolled [' .
                             implode(', ', $dieVals) . ']';
            if ($playerData['slowButton']) {
                $slowButtonPlayers[] = $playerId;
            }
        }
        $messageArray[] = 'Initial die values: ' . implode(', ', $dieRollStrs);

        // Now report on slow buttons and dice: assume a 2-player game for now
        if (count($slowButtonPlayers) == 2) {
            $messageArray[] = 'Both buttons have the "slow" button special, and cannot win initiative normally';
        } elseif (count($slowButtonPlayers) == 1) {
            $messageArray[] = $this->outputPlayerIdNames[$slowButtonPlayers[0]] .
                              '\'s button has the "slow" button special, and cannot win initiative normally';
        } else {
            foreach ($slowDice as $playerId => $playerSlowDice) {
                if (count($playerSlowDice) > 0) {
                    $messageArray[] = $this->outputPlayerIdNames[$playerId] .
                                      ' has dice which are not counted for initiative due to die skills: [' .
                                      implode(', ', $playerSlowDice) . ']';
                }
            }
        }

        // Last, if initiative was resolved by coin flip, say that.
        if (array_key_exists('tiedPlayerIds', $this->params)) {
            $messageArray[] = 'Initiative was determined by a coin flip';
        }

        $message = implode('. ', $messageArray) . '.';

        return $message;
    }

    /**
     * Describes the case when a player gets another turn
     *
     * @return string
     */
    protected function friendly_message_play_another_turn() {
        $message = $this->outputPlayerIdNames[$this->actingPlayerId] .
                   ' gets another turn';

        if ('TimeAndSpace' == $this->params['cause']) {
            $message .= ' because a Time and Space die rolled odd';
        }

        return $message;
    }

    /**
     * Describes ornery die rerolls at the end of each turn
     *
     * @return string
     */
    protected function friendly_message_ornery_reroll() {
        $messageArray = array();
        // Report what happened to each rerolling die
        foreach ($this->params['postRerollDieInfo'] as $idx => $postInfo) {
            if (!isset($postInfo['hasJustRerolledOrnery']) ||
                !$postInfo['hasJustRerolledOrnery']) {
                continue;
            }

            $dieMessageArray = array();

            $preInfo = $this->params['preRerollDieInfo'][$idx];

            $this->message_append(
                $dieMessageArray,
                $this->message_size_change($preInfo, $postInfo)
            );
            $this->message_append(
                $dieMessageArray,
                $this->message_recipe_change($preInfo, $postInfo)
            );
            $this->message_append(
                $dieMessageArray,
                $this->message_value_change($preInfo, $postInfo)
            );

            if (!empty($dieMessageArray)) {
                $messageArray[] = $preInfo['recipe'] . ' ' . implode(', ', $dieMessageArray);
            }
        }

        if (empty($messageArray)) {
            $message = '';
        } else {
            $message = $this->outputPlayerIdNames[$this->actingPlayerId] .
                       '\'s idle ornery dice rerolled at end of turn: ' .
                       implode('; ', $messageArray);
        }

        return $message;
    }

    /**
     * Getter
     *
     * @param string $property
     * @return mixed
     */
    public function __get($property) {
        if (property_exists($this, $property)) {
            switch ($property) {
                default:
                    return $this->$property;
            }
        }
    }

    /**
     * Setter
     *
     * @param string $property
     * @param mixed $value
     */
    public function __set($property, $value) {
        switch ($property) {
            default:
                $this->$property = $value;
        }
    }
}
