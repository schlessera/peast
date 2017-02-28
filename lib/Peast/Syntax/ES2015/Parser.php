<?php
/**
 * This file is part of the Peast package
 *
 * (c) Marco Marchiò <marco.mm89@gmail.com>
 *
 * For the full copyright and license information refer to the LICENSE file
 * distributed with this source code
 */
namespace Peast\Syntax\ES2015;

use Peast\Syntax\Token;
use \Peast\Syntax\Node;

/**
 * ES2015 parser class
 * 
 * @author Marco Marchiò <marco.mm89@gmail.com>
 */
class Parser extends \Peast\Syntax\Parser
{
    //Identifier parsing mode constants
    /**
     * Everything is allowed as identifier, including keywords, null and booleans
     */
    const ID_ALLOW_ALL = 1;
    
    /**
     * Keywords, null and booleans are not allowed in any situation
     */
    const ID_ALLOW_NOTHING = 2;
    
    /**
     * Keywords, null and booleans are not allowed in any situation, future
     * reserved words are allowed if not in strict mode
     */
    const ID_MIXED = 3;
    
    /**
     * Assignment operators
     * 
     * @var array 
     */
    protected $assignmentOperators = array(
        "=", "+=", "-=", "*=", "/=", "%=", "<<=", ">>=", ">>>=", "&=", "^=",
        "|="
    );
    
    /**
     * Logical and binary operators
     * 
     * @var array 
     */
    protected $logicalBinaryOperators = array(
        "||" => 0,
        "&&" => 1,
        "|" => 2,
        "^" => 3,
        "&" => 4,
        "===" => 5, "!==" => 5, "==" => 5, "!=" => 5,
        "<=" => 6, ">=" => 6, "<" => 6, ">" => 6,
        "instanceof" => 6, "in" => 6,
        ">>>" => 7, "<<" => 7, ">>" => 7,
        "+" => 8, "-" => 8,
        "*" => 9, "/" => 9, "%" => 9
    );
    
    /**
     * Configurable lookaheads
     * 
     * @var array 
     */
    protected $lookahead = array(
        "export" => array(
            "tokens" => array("function", "class"),
            "next"=> false
        ),    
        "expression" => array(
            "tokens" => array("{", "function", "class", array("let", "[")),
            "next"=> true
        )
    );
    
    /**
     * Unary operators
     * 
     * @var array 
     */
    protected $unaryOperators = array(
        "delete", "void", "typeof", "++", "--", "+", "-", "~", "!"
    );
    
    /**
     * Postfix operators
     * 
     * @var array 
     */
    protected $postfixOperators = array("--", "++");
    
    /**
     * Initializes parser context
     * 
     * @return void
     */
    protected function initContext()
    {
        $this->context = (object) array(
            "allowReturn" => false,
            "allowIn" => false,
            "allowYield" => false
        );
    }
    
    /**
     * Parses the source
     * 
     * @return Node\Program
     */
    public function parse()
    {
        if ($this->sourceType === \Peast\Peast::SOURCE_TYPE_MODULE) {
            $this->scanner->setStrictMode(true);
            $body = $this->parseModuleItemList();
        } else {
            $body = $this->parseStatementList(true);
        }
        
        $node = $this->createNode(
            "Program", $body ? $body : $this->scanner->getPosition()
        );
        $node->setSourceType($this->sourceType);
        if ($body) {
            $node->setBody($body);
        }
        $program = $this->completeNode($node);
        if ($this->scanner->getToken()) {
            return $this->error();
        }
        return $program;
    }
    
    /**
     * Converts an expression node to a pattern node
     * 
     * @param Node\Node $node The node to convert
     * 
     * @return Node\Node
     */
    protected function expressionToPattern($node)
    {
        $retNode = $node;
        if ($node instanceof Node\ArrayExpression) {
            
            $loc = $node->getLocation();
            $elems = array();
            foreach ($node->getElements() as $elem) {
                $elems[] = $this->expressionToPattern($elem);
            }
                
            $retNode = $this->createNode("ArrayPattern", $loc->getStart());
            $retNode->setElements($elems);
            $this->completeNode($retNode, $loc->getEnd());
            
        } elseif ($node instanceof Node\ObjectExpression) {
            
            $loc = $node->getLocation();
            $props = array();
            foreach ($node->getProperties() as $prop) {
                $props[] = $this->expressionToPattern($prop);
            }
                
            $retNode = $this->createNode("ObjectPattern", $loc->getStart());
            $retNode->setProperties($props);
            $this->completeNode($retNode, $loc->getEnd());
            
        } elseif ($node instanceof Node\Property) {
            
            $loc = $node->getLocation();
            $retNode = $this->createNode(
                "AssignmentProperty", $loc->getStart()
            );
            $retNode->setValue($node->getValue());
            $retNode->setKey($node->getKey());
            $retNode->setMethod($node->getMethod());
            $retNode->setShorthand($node->getShorthand());
            $retNode->setComputed($node->getComputed());
            $this->completeNode($retNode, $loc->getEnd());
            
        } elseif ($node instanceof Node\SpreadElement) {
            
            $loc = $node->getLocation();
            $retNode = $this->createNode("RestElement", $loc->getStart());
            $retNode->setArgument(
                $this->expressionToPattern($node->getArgument())
            );
            $this->completeNode($retNode, $loc->getEnd());
            
        } elseif ($node instanceof Node\AssignmentExpression) {
            
            $loc = $node->getLocation();
            $retNode = $this->createNode("AssignmentPattern", $loc->getStart());
            $retNode->setLeft($this->expressionToPattern($node->getLeft()));
            $retNode->setRight($node->getRight());
            $this->completeNode($retNode, $loc->getEnd());
            
        }
        return $retNode;
    }
    
    /**
     * Parses a statement list
     * 
     * @param bool $parseDirectivePrologues True to parse directive prologues
     * 
     * @return Node\Node[]|null
     */
    protected function parseStatementList(
        $parseDirectivePrologues = false
    ) {
        $items = array();
        
        //Get directive prologues and check if strict mode is present
        if ($parseDirectivePrologues) {
            $oldStrictMode = $this->scanner->getStrictMode();
            if ($directives = $this->parseDirectivePrologues()) {
                $items = array_merge($items, $directives[0]);
                //If "use strict" is present enable scanner strict mode
                if (in_array("use strict", $directives[1])) {
                    $this->scanner->setStrictMode(true);
                }
            }
        }
        
        while ($item = $this->parseStatementListItem()) {
            $items[] = $item;
        }
        
        //Apply previous strict mode
        if ($parseDirectivePrologues) {
            $this->scanner->setStrictMode($oldStrictMode);
        }
        
        return count($items) ? $items : null;
    }
    
    /**
     * Parses a statement list item
     * 
     * @return Node\Statement|Node\Declaration|null
     */
    protected function parseStatementListItem()
    {
        if ($declaration = $this->parseDeclaration()) {
            return $declaration;
        } elseif ($statement = $this->parseStatement()) {
            return $statement;
        }
        return null;
    }
    
    /**
     * Parses a statement
     * 
     * @return Node\Statement|null
     */
    protected function parseStatement()
    {
        if ($statement = $this->parseBlock()) {
            return $statement;
        } elseif ($statement = $this->parseVariableStatement()) {
            return $statement;
        } elseif ($statement = $this->parseEmptyStatement()) {
            return $statement;
        } elseif ($statement = $this->parseIfStatement()) {
            return $statement;
        } elseif ($statement = $this->parseBreakableStatement()) {
            return $statement;
        } elseif ($statement = $this->parseContinueStatement()) {
            return $statement;
        } elseif ($statement = $this->parseBreakStatement()) {
            return $statement;
        } elseif ($this->context->allowReturn && $statement = $this->parseReturnStatement()) {
            return $statement;
        } elseif ($statement = $this->parseWithStatement()) {
            return $statement;
        } elseif ($statement = $this->parseThrowStatement()) {
            return $statement;
        } elseif ($statement = $this->parseTryStatement()) {
            return $statement;
        } elseif ($statement = $this->parseDebuggerStatement()) {
            return $statement;
        } elseif ($statement = $this->parseLabelledStatement()) {
            return $statement;
        } elseif ($statement = $this->parseExpressionStatement()) {
            return $statement;
        }
        return null;
    }
    
    /**
     * Parses a declaration
     * 
     * @return Node\Declaration|null
     */
    protected function parseDeclaration()
    {
        if ($declaration = $this->parseFunctionOrGeneratorDeclaration()) {
            return $declaration;
        } elseif ($declaration = $this->parseClassDeclaration()) {
            return $declaration;
        } elseif (
            $declaration = $this->isolateContext(
                array("allowIn" => true), "parseLexicalDeclaration"
            )
        ) {
            return $declaration;
        }
        return null;
    }
    
    /**
     * Parses a breakable statement
     * 
     * @return Node\Node|null
     */
    protected function parseBreakableStatement()
    {
        if ($statement = $this->parseIterationStatement()) {
            return $statement;
        } elseif ($statement = $this->parseSwitchStatement()) {
            return $statement;
        }
        return null;
    }
    
    /**
     * Parses a block statement
     * 
     * @return Node\BlockStatement|null
     */
    protected function parseBlock()
    {
        if ($token = $this->scanner->consume("{")) {
            
            $statements = $this->parseStatementList();
            if ($this->scanner->consume("}")) {
                $node = $this->createNode("BlockStatement", $token);
                if ($statements) {
                    $node->setBody($statements);
                }
                return $this->completeNode($node);
            }
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a module item list
     * 
     * @return Node\Node[]|null
     */
    protected function parseModuleItemList()
    {
        $items = array();
        while ($item = $this->parseModuleItem()) {
            $items[] = $item;
        }
        return count($items) ? $items : null;
    }
    
    /**
     * Parses an empty statement
     * 
     * @return Node\EmptyStatement|null
     */
    protected function parseEmptyStatement()
    {
        if ($token = $this->scanner->consume(";")) {
            $node = $this->createNode("EmptyStatement", $token);
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses a debugger statement
     * 
     * @return Node\DebuggerStatement|null
     */
    protected function parseDebuggerStatement()
    {
        if ($token = $this->scanner->consume("debugger")) {
            $node = $this->createNode("DebuggerStatement", $token);
            $this->assertEndOfStatement();
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses an if statement
     * 
     * @return Node\IfStatement|null
     */
    protected function parseIfStatement()
    {
        if ($token = $this->scanner->consume("if")) {
            
            if ($this->scanner->consume("(") &&
                ($test = $this->isolateContext(
                    array("allowIn" => true), "parseExpression"
                )) &&
                $this->scanner->consume(")") &&
                (
                    ($consequent = $this->parseStatement()) ||
                    (!$this->scanner->getStrictMode() &&
                    $consequent = $this->parseFunctionOrGeneratorDeclaration(
                        false, false
                    ))
                )
            ) {
                
                $node = $this->createNode("IfStatement", $token);
                $node->setTest($test);
                $node->setConsequent($consequent);
                
                if ($this->scanner->consume("else")) {
                    if (($alternate = $this->parseStatement()) ||
                        (!$this->scanner->getStrictMode() &&
                        $alternate = $this->parseFunctionOrGeneratorDeclaration(
                            false, false
                        ))
                    ) {
                        $node->setAlternate($alternate);
                        return $this->completeNode($node);
                    }
                } else {
                    return $this->completeNode($node);
                }
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a try-catch statement
     * 
     * @return Node\TryStatement|null
     */
    protected function parseTryStatement()
    {
        if ($token = $this->scanner->consume("try")) {
            
            if ($block = $this->parseBlock()) {
                
                $node = $this->createNode("TryStatement", $token);
                $node->setBlock($block);

                if ($handler = $this->parseCatch()) {
                    $node->setHandler($handler);
                }

                if ($finalizer = $this->parseFinally()) {
                    $node->setFinalizer($finalizer);
                }

                if ($handler || $finalizer) {
                    return $this->completeNode($node);
                }
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses the catch block of a try-catch statement
     * 
     * @return Node\CatchClause|null
     */
    protected function parseCatch()
    {
        if ($token = $this->scanner->consume("catch")) {
            
            if ($this->scanner->consume("(") &&
                ($param = $this->parseCatchParameter()) &&
                $this->scanner->consume(")") &&
                $body = $this->parseBlock()
            ) {

                $node = $this->createNode("CatchClause", $token);
                $node->setParam($param);
                $node->setBody($body);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses the catch parameter of a catch block in a try-catch statement
     * 
     * @return Node|null
     */
    protected function parseCatchParameter()
    {
        if ($param = $this->parseIdentifier(self::ID_MIXED)) {
            return $param;
        } elseif ($param = $this->parseBindingPattern()) {
            return $param;
        }
        return null;
    }
    
    /**
     * Parses a finally block in a try-catch statement
     * 
     * @return Node\BlockStatement|null
     */
    protected function parseFinally()
    {
        if ($this->scanner->consume("finally")) {
            
            if ($block = $this->parseBlock()) {
                return $block;
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a countinue statement
     * 
     * @return Node\ContinueStatement|null
     */
    protected function parseContinueStatement()
    {
        if ($token = $this->scanner->consume("continue")) {
            
            $node = $this->createNode("ContinueStatement", $token);
            
            if ($this->scanner->noLineTerminators()) {
                if ($label = $this->parseIdentifier(self::ID_MIXED)) {
                    $node->setLabel($label);
                }
            }
            
            $this->assertEndOfStatement();
            
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses a break statement
     * 
     * @return Node\BreakStatement|null
     */
    protected function parseBreakStatement()
    {
        if ($token = $this->scanner->consume("break")) {
            
            $node = $this->createNode("BreakStatement", $token);
            
            if ($this->scanner->noLineTerminators()) {
                if ($label = $this->parseIdentifier(self::ID_MIXED)) {
                    $node->setLabel($label);
                }
            }
            
            $this->assertEndOfStatement();
            
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses a return statement
     * 
     * @return Node\ReturnStatement|null
     */
    protected function parseReturnStatement()
    {
        if ($token = $this->scanner->consume("return")) {
            
            $node = $this->createNode("ReturnStatement", $token);
            
            if ($this->scanner->noLineTerminators()) {
                $argument = $this->isolateContext(
                    array("allowIn" => true), "parseExpression"
                );
                if ($argument) {
                    $node->setArgument($argument);
                }
            }
            
            $this->assertEndOfStatement();
            
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses a labelled statement
     * 
     * @return Node\LabeledStatement|null
     */
    protected function parseLabelledStatement()
    {
        if ($label = $this->parseIdentifier(self::ID_MIXED, ":")) {
            
            $this->scanner->consume(":");
                
            if (($body = $this->parseStatement()) ||
                ($body = $this->parseFunctionOrGeneratorDeclaration(
                    false, false
                ))
            ) {
                
                //Labelled functions are not allowed in strict mode 
                if ($body instanceof Node\FunctionDeclaration &&
                    $this->scanner->getStrictMode()) {
                    return $this->error(
                        "Labelled functions are not allowed in strict mode"
                    );
                }

                $node = $this->createNode("LabeledStatement", $label);
                $node->setLabel($label);
                $node->setBody($body);
                return $this->completeNode($node);

            }

            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a throw statement
     * 
     * @return Node\ThrowStatement|null
     */
    protected function parseThrowStatement()
    {
        if ($token = $this->scanner->consume("throw")) {
            
            if ($this->scanner->noLineTerminators() &&
                ($argument = $this->isolateContext(
                    array("allowIn" => true), "parseExpression"
                ))
            ) {
                
                $this->assertEndOfStatement();
                $node = $this->createNode("ThrowStatement", $token);
                $node->setArgument($argument);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a with statement
     * 
     * @return Node\WithStatement|null
     */
    protected function parseWithStatement()
    {
        if ($token = $this->scanner->consume("with")) {
            
            if ($this->scanner->consume("(") &&
                ($object = $this->isolateContext(
                    array("allowIn" => true), "parseExpression"
                )) &&
                $this->scanner->consume(")") &&
                $body = $this->parseStatement()
            ) {
            
                $node = $this->createNode("WithStatement", $token);
                $node->setObject($object);
                $node->setBody($body);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a switch statement
     * 
     * @return Node\SwitchStatement|null
     */
    protected function parseSwitchStatement()
    {
        if ($token = $this->scanner->consume("switch")) {
            
            if ($this->scanner->consume("(") &&
                ($discriminant = $this->isolateContext(
                    array("allowIn" => true), "parseExpression"
                )) &&
                $this->scanner->consume(")") &&
                ($cases = $this->parseCaseBlock()) !== null
            ) {
            
                $node = $this->createNode("SwitchStatement", $token);
                $node->setDiscriminant($discriminant);
                $node->setCases($cases);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses the content of a switch statement
     * 
     * @return Node\SwitchCase[]|null
     */
    protected function parseCaseBlock()
    {
        if ($this->scanner->consume("{")) {
            
            $parsedCasesAll = array(
                $this->parseCaseClauses(),
                $this->parseDefaultClause(),
                $this->parseCaseClauses()
            );
            
            if ($this->scanner->consume("}")) {
                $cases = array();
                foreach ($parsedCasesAll as $parsedCases) {
                    if ($parsedCases) {
                        if (is_array($parsedCases)) {
                            $cases = array_merge($cases, $parsedCases);
                        } else {
                            $cases[] = $parsedCases;
                        }
                    }
                }
                return $cases;
            } elseif ($this->parseDefaultClause()) {
                return $this->error(
                    "Multiple default clause in switch statement"
                );
            } else {
                return $this->error();
            }
        }
        return null;
    }
    
    /**
     * Parses cases in a switch statement
     * 
     * @return Node\SwitchCase[]|null
     */
    protected function parseCaseClauses()
    {
        $cases = array();
        while ($case = $this->parseCaseClause()) {
            $cases[] = $case;
        }
        return count($cases) ? $cases : null;
    }
    
    /**
     * Parses a case in a switch statement
     * 
     * @return Node\SwitchCase|null
     */
    protected function parseCaseClause()
    {
        if ($token = $this->scanner->consume("case")) {
            
            if (($test = $this->isolateContext(
                    array("allowIn" => true), "parseExpression"
                )) &&
                $this->scanner->consume(":")
            ) {

                $node = $this->createNode("SwitchCase", $token);
                $node->setTest($test);

                if ($consequent = $this->parseStatementList()) {
                    $node->setConsequent($consequent);
                }

                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses default case in a switch statement
     * 
     * @return Node\SwitchCase|null
     */
    protected function parseDefaultClause()
    {
        if ($token = $this->scanner->consume("default")) {
            
            if ($this->scanner->consume(":")) {

                $node = $this->createNode("SwitchCase", $token);
            
                if ($consequent = $this->parseStatementList()) {
                    $node->setConsequent($consequent);
                }

                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an expression statement
     * 
     * @return Node\ExpressionStatement|null
     */
    protected function parseExpressionStatement()
    {
        if (!$this->scanner->isBefore(
                $this->lookahead["expression"]["tokens"],
                $this->lookahead["expression"]["next"]
            ) &&
            $expression = $this->isolateContext(
                array("allowIn" => true), "parseExpression"
            )
        ) {
            
            $this->assertEndOfStatement();
            $node = $this->createNode("ExpressionStatement", $expression);
            $node->setExpression($expression);
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses do-while, while, for, for-in and for-of statements
     * 
     * @return Node\Node|null
     */
    protected function parseIterationStatement()
    {
        if ($token = $this->scanner->consume("do")) {
            
            if (($body = $this->parseStatement()) &&
                $this->scanner->consume("while") &&
                $this->scanner->consume("(") &&
                ($test = $this->isolateContext(
                    array("allowIn" => true), "parseExpression"
                )) &&
                $this->scanner->consume(")")
            ) {
                    
                $node = $this->createNode("DoWhileStatement", $token);
                $node->setBody($body);
                $node->setTest($test);
                return $this->completeNode($node);
            }
            return $this->error();
            
        } elseif ($token = $this->scanner->consume("while")) {
            
            if ($this->scanner->consume("(") &&
                ($test = $this->isolateContext(
                    array("allowIn" => true), "parseExpression"
                )) &&
                $this->scanner->consume(")") &&
                $body = $this->parseStatement()
            ) {
                    
                $node = $this->createNode("WhileStatement", $token);
                $node->setTest($test);
                $node->setBody($body);
                return $this->completeNode($node);
            }
            return $this->error();
            
        } elseif ($token = $this->scanner->consume("for")) {
            
            $hasBracket = $this->scanner->consume("(");
            $afterBracketState = $this->scanner->getState();
            
            if (!$hasBracket) {
                return $this->error();
            } elseif ($varToken = $this->scanner->consume("var")) {
                
                $state = $this->scanner->getState();
                
                if (($decl = $this->isolateContext(
                        array("allowIn" => false), "parseVariableDeclarationList"
                    )) &&
                    ($varEndPosition = $this->scanner->getPosition()) &&
                    $this->scanner->consume(";")
                ) {
                            
                    $init = $this->createNode(
                        "VariableDeclaration", $varToken
                    );
                    $init->setKind($init::KIND_VAR);
                    $init->setDeclarations($decl);
                    $init = $this->completeNode($init, $varEndPosition);
                    
                    $test = $this->isolateContext(
                        array("allowIn" => true), "parseExpression"
                    );
                    
                    if ($this->scanner->consume(";")) {
                        
                        $update = $this->isolateContext(
                            array("allowIn" => true), "parseExpression"
                        );
                        
                        if ($this->scanner->consume(")") &&
                            $body = $this->parseStatement()
                        ) {
                            
                            $node = $this->createNode("ForStatement", $token);
                            $node->setInit($init);
                            $node->setTest($test);
                            $node->setUpdate($update);
                            $node->setBody($body);
                            return $this->completeNode($node);
                        }
                    }
                } else {
                    
                    $this->scanner->setState($state);
                    
                    if ($decl = $this->parseForBinding()) {
                        
                        $left = $this->createNode(
                            "VariableDeclaration", $varToken
                        );
                        $left->setKind($left::KIND_VAR);
                        $left->setDeclarations(array($decl));
                        $left = $this->completeNode($left);
                        
                        if ($this->scanner->consume("in")) {
                            
                            if (($right = $this->isolateContext(
                                    array("allowIn" => true), "parseExpression"
                                )) &&
                                $this->scanner->consume(")") &&
                                $body = $this->parseStatement()
                            ) {
                                
                                $node = $this->createNode(
                                    "ForInStatement", $token
                                );
                                $node->setLeft($left);
                                $node->setRight($right);
                                $node->setBody($body);
                                return $this->completeNode($node);
                            }
                        } elseif ($this->scanner->consume("of")) {
                            
                            if (($right = $this->isolateContext(
                                    array("allowIn" => true),
                                    "parseAssignmentExpression"
                                )) &&
                                $this->scanner->consume(")") &&
                                $body = $this->parseStatement()
                            ) {
                                
                                $node = $this->createNode(
                                    "ForOfStatement", $token
                                );
                                $node->setLeft($left);
                                $node->setRight($right);
                                $node->setBody($body);
                                return $this->completeNode($node);
                            }
                        }
                    }
                }
            } elseif ($init = $this->parseForDeclaration()) {
                
                if ($init && $this->scanner->consume("in")) {
                    if (($right = $this->isolateContext(
                            array("allowIn" => true), "parseExpression"
                        )) &&
                        $this->scanner->consume(")") &&
                        $body = $this->parseStatement()
                    ) {
                        
                        $node = $this->createNode("ForInStatement", $token);
                        $node->setLeft($init);
                        $node->setRight($right);
                        $node->setBody($body);
                        return $this->completeNode($node);
                    }
                } elseif ($init && $this->scanner->consume("of")) {
                    if (($right = $this->isolateContext(
                            array("allowIn" => true),
                            "parseAssignmentExpression"
                        )) &&
                        $this->scanner->consume(")") &&
                        $body = $this->parseStatement()
                    ) {
                        
                        $node = $this->createNode("ForOfStatement", $token);
                        $node->setLeft($init);
                        $node->setRight($right);
                        $node->setBody($body);
                        return $this->completeNode($node);
                    }
                } else {
                    
                    $this->scanner->setState($afterBracketState);
                    if (
                        $init = $this->isolateContext(
                            array("allowIn" => false), "parseLexicalDeclaration"
                        )
                    ) {
                        
                        $test = $this->isolateContext(
                            array("allowIn" => true), "parseExpression"
                        );
                        if ($this->scanner->consume(";")) {
                                
                            $update = $this->isolateContext(
                                array("allowIn" => true), "parseExpression"
                            );
                            
                            if ($this->scanner->consume(")") &&
                                $body = $this->parseStatement()
                            ) {
                                
                                $node = $this->createNode(
                                    "ForStatement", $token
                                );
                                $node->setInit($init);
                                $node->setTest($test);
                                $node->setUpdate($update);
                                $node->setBody($body);
                                return $this->completeNode($node);
                            }
                        }
                    }
                }
                
            } elseif (!$this->scanner->isBefore(array("let"))) {
                
                $state = $this->scanner->getState();
                $notBeforeSB = !$this->scanner->isBefore(
                    array(array("let", "[")), true
                );
                
                if ($notBeforeSB &&
                    (($init = $this->isolateContext(
                        array("allowIn" => false), "parseExpression"
                    )) || true) &&
                    $this->scanner->consume(";")
                ) {
                
                    $test = $this->isolateContext(
                        array("allowIn" => true), "parseExpression"
                    );
                    
                    if ($this->scanner->consume(";")) {
                            
                        $update = $this->isolateContext(
                            array("allowIn" => true), "parseExpression"
                        );
                        
                        if ($this->scanner->consume(")") &&
                            $body = $this->parseStatement()
                        ) {
                            
                            $node = $this->createNode(
                                "ForStatement", $token
                            );
                            $node->setInit($init);
                            $node->setTest($test);
                            $node->setUpdate($update);
                            $node->setBody($body);
                            return $this->completeNode($node);
                        }
                    }
                } else {
                    
                    $this->scanner->setState($state);
                    $left = $this->parseLeftHandSideExpression();
                    $left = $this->expressionToPattern($left);
                    
                    if ($notBeforeSB && $left &&
                        $this->scanner->consume("in")
                    ) {
                        
                        if (($right = $this->isolateContext(
                                array("allowIn" => true), "parseExpression"
                            )) &&
                            $this->scanner->consume(")") &&
                            $body = $this->parseStatement()
                        ) {
                            
                            $node = $this->createNode(
                                "ForInStatement", $token
                            );
                            $node->setLeft($left);
                            $node->setRight($right);
                            $node->setBody($body);
                            return $this->completeNode($node);
                        }
                    } elseif ($left && $this->scanner->consume("of")) {
                        
                        if (($right = $this->isolateContext(
                                array("allowIn" => true),
                                "parseAssignmentExpression"
                            )) &&
                            $this->scanner->consume(")") &&
                            $body = $this->parseStatement()
                        ) {
                            
                            $node = $this->createNode(
                                "ForOfStatement", $token
                            );
                            $node->setLeft($left);
                            $node->setRight($right);
                            $node->setBody($body);
                            return $this->completeNode($node);
                        }
                    }
                }
            }
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses function or generator declaration
     * 
     * @param bool $default        Default mode
     * @param bool $allowGenerator True to allow parsing of generators
     * 
     * @return Node\FunctionDeclaration|null
     */
    protected function parseFunctionOrGeneratorDeclaration(
        $default = false, $allowGenerator = true
    ) {
        if ($token = $this->scanner->consume("function")) {
            
            $generator = $allowGenerator && $this->scanner->consume("*");
            $id = $this->parseIdentifier(self::ID_MIXED);
            
            if (($default || $id) &&
                $this->scanner->consume("(") &&
                ($params = $this->isolateContext(
                    $generator ? array("allowYield" => true) : null,
                    "parseFormalParameterList"
                )) !== null &&
                $this->scanner->consume(")") &&
                ($tokenBodyStart = $this->scanner->consume("{")) &&
                (($body = $this->isolateContext(
                    $generator ? array("allowYield" => true) : null,
                    "parseFunctionBody"
                )) || true) &&
                $this->scanner->consume("}")
            ) {
                
                $body->setStartPosition(
                    $tokenBodyStart->getLocation()->getStart()
                );
                $body->setEndPosition($this->scanner->getPosition());
                $node = $this->createNode("FunctionDeclaration", $token);
                if ($id) {
                    $node->setId($id);
                }
                $node->setParams($params);
                $node->setBody($body);
                $node->setGenerator($generator);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses function or generator expression
     * 
     * @return Node\FunctionExpression|null
     */
    protected function parseFunctionOrGeneratorExpression()
    {
        if ($token = $this->scanner->consume("function")) {
            
            $generator = (bool) $this->scanner->consume("*");
            $id = $this->parseIdentifier(self::ID_MIXED);
            
            if ($this->scanner->consume("(") &&
                ($params = $this->isolateContext(
                    $generator ? array("allowYield" => true) : null,
                    "parseFormalParameterList"
                )) !== null &&
                $this->scanner->consume(")") &&
                ($tokenBodyStart = $this->scanner->consume("{")) &&
                (($body = $this->isolateContext(
                    $generator ? array("allowYield" => true) : null,
                    "parseFunctionBody"
                )) || true) &&
                $this->scanner->consume("}")
            ) {
                
                $body->setStartPosition(
                    $tokenBodyStart->getLocation()->getStart()
                );
                $body->setEndPosition($this->scanner->getPosition());
                $node = $this->createNode("FunctionExpression", $token);
                $node->setId($id);
                $node->setParams($params);
                $node->setBody($body);
                $node->setGenerator($generator);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses yield statement
     * 
     * @return Node\YieldExpression|null
     */
    protected function parseYieldExpression()
    {
        if ($token = $this->scanner->consume("yield")) {
            
            $node = $this->createNode("YieldExpression", $token);
            if ($this->scanner->noLineTerminators()) {
                
                $delegate = $this->scanner->consume("*");
                $argument = $this->isolateContext(
                    array("allowYield" => true), "parseAssignmentExpression"
                );
                if ($argument) {
                    $node->setArgument($argument);
                    $node->setDelegate($delegate);
                }
            }
            
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses a parameter list
     * 
     * @return Node\Node[]|null
     */
    protected function parseFormalParameterList()
    {
        $valid = true;
        $list = array();
        while (
            ($param = $this->parseBindingRestElement()) ||
            $param = $this->parseBindingElement()
        ) {
            $valid = true;
            $list[] = $param;
            if ($param->getType() === "RestElement") {
                break;
            } elseif ($this->scanner->consume(",")) {
                $valid = false;
            } else {
                break;
            }
        }
        if (!$valid) {
            return $this->error();
        }
        return $list;
    }
    
    /**
     * Parses a function body
     * 
     * @return Node\BlockStatement[]|null
     */
    protected function parseFunctionBody()
    {
        $body = $this->isolateContext(
            array("allowReturn" => true),
            "parseStatementList",
            array(true)
        );
        $node = $this->createNode(
            "BlockStatement", $body ? $body : $this->scanner->getPosition()
        );
        if ($body) {
            $node->setBody($body);
        }
        return $this->completeNode($node);
    }
    
    /**
     * Parses a class declaration
     * 
     * @param bool $default Default mode
     * 
     * @return Node\ClassDeclaration|null
     */
    protected function parseClassDeclaration($default = false)
    {
        if ($token = $this->scanner->consume("class")) {
            
            $id = $this->parseIdentifier(self::ID_ALLOW_NOTHING);
            if (($default || $id) &&
                $tail = $this->parseClassTail()
            ) {
                
                $node = $this->createNode("ClassDeclaration", $token);
                if ($id) {
                    $node->setId($id);
                }
                if ($tail[0]) {
                    $node->setSuperClass($tail[0]);
                }
                $node->setBody($tail[1]);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a class expression
     * 
     * @return Node\ClassExpression|null
     */
    protected function parseClassExpression()
    {
        if ($token = $this->scanner->consume("class")) {
            $id = $this->parseIdentifier(self::ID_ALLOW_NOTHING);
            $tail = $this->parseClassTail();
            $node = $this->createNode("ClassExpression", $token);
            if ($id) {
                $node->setId($id);
            }
            if ($tail[0]) {
                $node->setSuperClass($tail[0]);
            }
            $node->setBody($tail[1]);
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses the code that comes after the class keyword and class name. The
     * return value is an array where the first item is the extendend class, if
     * any, and the second value is the class body
     * 
     * @return array|null
     */
    protected function parseClassTail()
    {
        $heritage = $this->parseClassHeritage();
        if ($token = $this->scanner->consume("{")) {
            
            $body = $this->parseClassBody();
            if ($this->scanner->consume("}")) {
                $body->setStartPosition($token->getLocation()->getStart());
                $body->setEndPosition($this->scanner->getPosition());
                return array($heritage, $body);
            }
        }
        return $this->error();
    }
    
    /**
     * Parses the class extends part
     * 
     * @return Node\Node|null
     */
    protected function parseClassHeritage()
    {
        if ($this->scanner->consume("extends")) {
            
            if ($superClass = $this->parseLeftHandSideExpression()) {
                return $superClass;
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses the class body
     * 
     * @return Node\ClassBody|null
     */
    protected function parseClassBody()
    {
        $body = $this->parseClassElementList();
        $node = $this->createNode(
            "ClassBody", $body ? $body : $this->scanner->getPosition()
        );
        if ($body) {
            $node->setBody($body);
        }
        return $this->completeNode($node);
    }
    
    /**
     * Parses class elements list
     * 
     * @return Node\MethodDefinition[]|null
     */
    protected function parseClassElementList()
    {
        $items = array();
        while ($item = $this->parseClassElement()) {
            if ($item !== true) {
                $items[] = $item;
            }
        }
        return count($items) ? $items : null;
    }
    
    /**
     * Parses a class elements
     * 
     * @return Node\MethodDefinition|null
     */
    protected function parseClassElement()
    {
        if ($this->scanner->consume(";")) {
            return true;
        }
        
        $staticToken = $this->scanner->consume("static");
        if ($def = $this->parseMethodDefinition()) {
            if ($staticToken) {
                $def->setStatic(true);
                $def->setStartPosition($staticToken->getLocation()->getStart());
            }
            return $def;
        } elseif ($staticToken) {
            return $this->error();
        }
        
        return null;
    }
    
    /**
     * Parses a let or const declaration
     * 
     * @return Node\VariableDeclaration|null
     */
    protected function parseLexicalDeclaration()
    {
        if ($token = $this->scanner->consumeOneOf(array("let", "const"))) {
            
            $declarations = $this->charSeparatedListOf(
                "parseVariableDeclaration"
            );
            
            if ($declarations) {
                $this->assertEndOfStatement();
                $node = $this->createNode("VariableDeclaration", $token);
                $node->setKind($token->getValue());
                $node->setDeclarations($declarations);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a var declaration
     * 
     * @return Node\VariableDeclaration|null
     */
    protected function parseVariableStatement()
    {
        if ($token = $this->scanner->consume("var")) {
            
            $declarations = $this->isolateContext(
                array("allowIn" => true), "parseVariableDeclarationList"
            );
            if ($declarations) {
                $this->assertEndOfStatement();
                $node = $this->createNode("VariableDeclaration", $token);
                $node->setKind($node::KIND_VAR);
                $node->setDeclarations($declarations);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an variable declarations
     * 
     * @return Node\VariableDeclarator[]|null
     */
    protected function parseVariableDeclarationList()
    {
        return $this->charSeparatedListOf(
            "parseVariableDeclaration"
        );
    }
    
    /**
     * Parses a variable declarations
     * 
     * @return Node\VariableDeclarator|null
     */
    protected function parseVariableDeclaration()
    {
        if ($id = $this->parseIdentifier(self::ID_MIXED)) {
            
            $node = $this->createNode("VariableDeclarator", $id);
            $node->setId($id);
            if ($init = $this->parseInitializer()) {
                $node->setInit($init);
            }
            return $this->completeNode($node);
            
        } elseif ($id = $this->parseBindingPattern()) {
            
            if ($init = $this->parseInitializer()) {
                $node = $this->createNode("VariableDeclarator", $id);
                $node->setId($id);
                $node->setInit($init);
                return $this->completeNode($node);
            }
            
        }
        return null;
    }
    
    /**
     * Parses a let or const declaration in a for statement definition
     * 
     * @return Node\VariableDeclaration|null
     */
    protected function parseForDeclaration()
    {
        if ($token = $this->scanner->consumeOneOf(array("let", "const"))) {
            
            if ($declaration = $this->parseForBinding()) {

                $node = $this->createNode("VariableDeclaration", $token);
                $node->setKind($token->getValue());
                $node->setDeclarations(array($declaration));
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a binding pattern or an identifier that come after a const or let
     * declaration in a for statement definition
     * 
     * @return Node\VariableDeclarator|null
     */
    protected function parseForBinding()
    {
        if (($id = $this->parseIdentifier(self::ID_MIXED)) ||
            ($id = $this->parseBindingPattern())
        ) {
            
            $node = $this->createNode("VariableDeclarator", $id);
            $node->setId($id);
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses a module item
     * 
     * @return Node\Node|null
     */
    protected function parseModuleItem()
    {
        if ($item = $this->parseImportDeclaration()) {
            return $item;
        } elseif ($item = $this->parseExportDeclaration()) {
            return $item;
        } elseif ($item = $this->parseStatementListItem()) {
            return $item;
        }
        return null;
    }
    
    /**
     * Parses the from keyword and the following string in import and export
     * declarations
     * 
     * @return Node\StringLiteral|null
     */
    protected function parseFromClause()
    {
        if ($this->scanner->consume("from")) {
            if ($spec = $this->parseStringLiteral()) {
                return $spec;
            }
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an export declaration
     * 
     * @return Node\ModuleDeclaration|null
     */
    protected function parseExportDeclaration()
    {
        if ($token = $this->scanner->consume("export")) {
            
            if ($this->scanner->consume("*")) {
                
                if ($source = $this->parseFromClause()) {
                    $this->assertEndOfStatement();
                    $node = $this->createNode("ExportAllDeclaration", $token);
                    $node->setSource($source);
                    return $this->completeNode($node);
                }
                
            } elseif ($this->scanner->consume("default")) {
                
                if (($declaration = $this->isolateContext(
                        null,
                        "parseFunctionOrGeneratorDeclaration",
                        array(true)
                    )) ||
                    ($declaration = $this->isolateContext(
                        null,
                        "parseClassDeclaration",
                        array(true)
                    ))
                ) {
                    
                    $node = $this->createNode("ExportDefaultDeclaration", $token);
                    $node->setDeclaration($declaration);
                    return $this->completeNode($node);
                    
                } elseif (!$this->scanner->isBefore(
                        $this->lookahead["export"]["tokens"],
                        $this->lookahead["export"]["next"]
                    ) &&
                    ($declaration = $this->isolateContext(
                        array(null, "allowIn" => true),
                        "parseAssignmentExpression"
                    ))
                ) {
                    
                    $this->assertEndOfStatement();
                    $node = $this->createNode(
                        "ExportDefaultDeclaration", $token
                    );
                    $node->setDeclaration($declaration);
                    return $this->completeNode($node);
                }
                
            } elseif (($specifiers = $this->parseExportClause()) !== null) {
                
                $node = $this->createNode("ExportNamedDeclaration", $token);
                $node->setSpecifiers($specifiers);
                if ($source = $this->parseFromClause()) {
                    $node->setSource($source);
                }
                $this->assertEndOfStatement();
                return $this->completeNode($node);

            } elseif (
                ($dec = $this->isolateContext(
                    null, "parseVariableStatement"
                )) ||
                $dec = $this->isolateContext(
                    null, "parseDeclaration"
                )
            ) {

                $node = $this->createNode("ExportNamedDeclaration", $token);
                $node->setDeclaration($dec);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an export clause
     * 
     * @return Node\ExportSpecifier[]|null
     */
    protected function parseExportClause()
    {
        if ($this->scanner->consume("{")) {
            
            $list = array();
            while ($spec = $this->parseExportSpecifier()) {
                $list[] = $spec;
                if (!$this->scanner->consume(",")) {
                    break;
                }
            }
            
            if ($this->scanner->consume("}")) {
                return $list;
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an export specifier
     * 
     * @return Node\ExportSpecifier|null
     */
    protected function parseExportSpecifier()
    {
        if ($local = $this->parseIdentifier(self::ID_ALLOW_ALL)) {
            
            $node = $this->createNode("ExportSpecifier", $local);
            $node->setLocal($local);
            
            if ($this->scanner->consume("as")) {
                
                if ($exported = $this->parseIdentifier(self::ID_ALLOW_ALL)) {
                    $node->setExported($exported);
                    return $this->completeNode($node);
                }
                
                return $this->error();
            } else {
                $node->setExported($local);
                return $this->completeNode($node);
            }
        }
        return null;
    }
    
    /**
     * Parses an import declaration
     * 
     * @return Node\ModuleDeclaration|null
     */
    protected function parseImportDeclaration()
    {
        if ($token = $this->scanner->consume("import")) {
            
            if ($source = $this->parseStringLiteral()) {
                
                $this->assertEndOfStatement();
                $node = $this->createNode("ImportDeclaration", $token);
                $node->setSource($source);
                return $this->completeNode($node);
                
            } elseif (($specifiers = $this->parseImportClause()) &&
                $source = $this->parseFromClause()
            ) {
                
                $this->assertEndOfStatement();
                $node = $this->createNode("ImportDeclaration", $token);
                $node->setSpecifiers($specifiers);
                $node->setSource($source);
                
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an import clause
     * 
     * @return Node\ModuleSpecifier|null
     */
    protected function parseImportClause()
    {
        if ($spec = $this->parseNameSpaceImport()) {
            return array($spec);
        } elseif ($specs = $this->parseNamedImports()) {
            return $specs;
        } elseif ($spec = $this->parseIdentifier(self::ID_ALLOW_NOTHING)) {
            
            $node = $this->createNode("ImportDefaultSpecifier", $spec);
            $node->setLocal($spec);
            $ret = array($this->completeNode($node));
            
            if ($this->scanner->consume(",")) {
                
                if ($spec = $this->parseNameSpaceImport()) {
                    $ret[] = $spec;
                    return $ret;
                } elseif ($specs = $this->parseNamedImports()) {
                    $ret = array_merge($ret, $specs);
                    return $ret;
                }
                
                return $this->error();
            } else {
                return $ret;
            }
        }
        return null;
    }
    
    /**
     * Parses a namespace import
     * 
     * @return Node\ImportNamespaceSpecifier|null
     */
    protected function parseNameSpaceImport()
    {
        if ($token = $this->scanner->consume("*")) {
            
            if ($this->scanner->consume("as") &&
                $local = $this->parseIdentifier(self::ID_ALLOW_NOTHING)
            ) {
                $node = $this->createNode("ImportNamespaceSpecifier", $token);
                $node->setLocal($local);
                return $this->completeNode($node);  
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a named imports
     * 
     * @return Node\ImportSpecifier[]|null
     */
    protected function parseNamedImports()
    {
        if ($this->scanner->consume("{")) {
            
            $list = array();
            while ($spec = $this->parseImportSpecifier()) {
                $list[] = $spec;
                if (!$this->scanner->consume(",")) {
                    break;
                }
            }
            
            if ($this->scanner->consume("}")) {
                return $list;
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an import specifier
     * 
     * @return Node\ImportSpecifier|null
     */
    protected function parseImportSpecifier()
    {
        if ($imported = $this->parseIdentifier(self::ID_ALLOW_NOTHING)) {
            
            $node = $this->createNode("ImportSpecifier", $imported);
            $node->setImported($imported);
            if ($this->scanner->consume("as")) {
                
                if ($local = $this->parseIdentifier(self::ID_ALLOW_NOTHING)) {
                    $node->setLocal($local);
                    return $this->completeNode($node);
                }
                
                return $this->error();
            } else {
                $node->setLocal($imported);
                return $this->completeNode($node);
            }
        }
        return null;
    }
    
    /**
     * Parses a binding pattern
     * 
     * @return Node\ArrayPattern|Node\ObjectPattern|null
     */
    protected function parseBindingPattern()
    {
        if ($pattern = $this->parseObjectBindingPattern()) {
            return $pattern;
        } elseif ($pattern = $this->parseArrayBindingPattern()) {
            return $pattern;
        }
        return null;
    }
    
    /**
     * Parses an elisions sequence. It returns the number of elisions or null
     * if no elision has been found
     * 
     * @return int
     */
    protected function parseElision()
    {
        $count = 0;
        while ($this->scanner->consume(",")) {
            $count ++;
        }
        return $count ? $count : null;
    }
    
    /**
     * Parses an array binding pattern
     * 
     * @return Node\ArrayPattern|null
     */
    protected function parseArrayBindingPattern()
    {
        if ($token = $this->scanner->consume("[")) {
            
            $elements = array();
            while (true) {
                if ($elision = $this->parseElision()) {
                    $elements = array_merge(
                        $elements, array_fill(0, $elision, null)
                    );
                }
                if ($element = $this->parseBindingElement()) {
                    $elements[] = $element;
                    if (!$this->scanner->consume(",")) {
                        break;
                    }
                } elseif ($rest = $this->parseBindingRestElement()) {
                    $elements[] = $rest;
                    break;
                } else {
                    break;
                }
            }
            
            if ($this->scanner->consume("]")) {
                $node = $this->createNode("ArrayPattern", $token);
                $node->setElements($elements);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a rest element
     * 
     * @return Node\RestElement|null
     */
    protected function parseBindingRestElement()
    {
        if ($token = $this->scanner->consume("...")) {
            
            if ($argument = $this->parseIdentifier(self::ID_MIXED)) {
                $node = $this->createNode("RestElement", $token);
                $node->setArgument($argument);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a binding element
     * 
     * @return Node\AssignmentPattern|Node\Identifier|null
     */
    protected function parseBindingElement()
    {
        if ($el = $this->parseSingleNameBinding()) {
            return $el;
        } elseif ($left = $this->parseBindingPattern()) {
            
            $right = $this->isolateContext(
                array("allowIn" => true), "parseInitializer"
            );
            if ($right) {
                $node = $this->createNode("AssignmentPattern", $left);
                $node->setLeft($left);
                $node->setRight($right);
                return $this->completeNode($node);
            } else {
                return $left;
            }
        }
        return null;
    }
    
    /**
     * Parses single name binding
     * 
     * @return Node\AssignmentPattern|Node\Identifier|null
     */
    protected function parseSingleNameBinding()
    {
        if ($left = $this->parseIdentifier(self::ID_MIXED)) {
            $right = $this->isolateContext(
                array("allowIn" => true), "parseInitializer"
            );
            if ($right) {
                $node = $this->createNode("AssignmentPattern", $left);
                $node->setLeft($left);
                $node->setRight($right);
                return $this->completeNode($node);
            } else {
                return $left;
            }
        }
        return null;
    }
    
    /**
     * Parses a property name. The returned value is an array where there first
     * element is the property name and the second element is a boolean
     * indicating if it's a computed property
     * 
     * @return array|null
     */
    protected function parsePropertyName()
    {
        if ($token = $this->scanner->consume("[")) {
            
            if (($name = $this->isolateContext(
                    array("allowIn" => true), "parseAssignmentExpression"
                )) &&
                $this->scanner->consume("]")
            ) {
                return array($name, true, $token);
            }
            
            return $this->error();
        } elseif ($name = $this->parseIdentifier(self::ID_ALLOW_ALL)) {
            return array($name, false);
        } elseif ($name = $this->parseStringLiteral()) {
            return array($name, false);
        } elseif ($name = $this->parseNumericLiteral()) {
            return array($name, false);
        }
        return null;
    }
    
    /**
     * Parses a method definition
     * 
     * @return Node\MethodDefinition|null
     */
    protected function parseMethodDefinition()
    {
        $state = $this->scanner->getState();
        $generator = false;
        $position = null;
        $error = false;
        $kind = Node\MethodDefinition::KIND_METHOD;
        if ($token = $this->scanner->consume("get")) {
            $position = $token;
            $kind = Node\MethodDefinition::KIND_GET;
            $error = true;
        } elseif ($token = $this->scanner->consume("set")) {
            $position = $token;
            $kind = Node\MethodDefinition::KIND_SET;
            $error = true;
        } elseif ($token = $this->scanner->consume("*")) {
            $position = $token;
            $error = true;
            $generator = true;
        }
        
        //Handle the case where get and set are methods name and not the
        //definition of a getter/setter
        if ($kind !== Node\MethodDefinition::KIND_METHOD &&
            $this->scanner->consume("(")
        ) {
            $this->scanner->setState($state);
            $kind = Node\MethodDefinition::KIND_METHOD;
            $error = false;
        }
        
        if ($prop = $this->parsePropertyName()) {
            
            if (!$position) {
                $position = isset($prop[2]) ? $prop[2] : $prop[0];
            }
            if ($tokenFn = $this->scanner->consume("(")) {
                
                $error = true;
                $params = array();
                if ($kind === Node\MethodDefinition::KIND_SET) {
                    $params = $this->isolateContext(
                        null, "parseBindingElement"
                    );
                    if ($params) {
                        $params = array($params);
                    }
                } elseif ($kind === Node\MethodDefinition::KIND_METHOD) {
                    $params = $this->isolateContext(
                        null, "parseFormalParameterList"
                    );
                }

                if ($params !== null &&
                    $this->scanner->consume(")") &&
                    ($tokenBodyStart = $this->scanner->consume("{")) &&
                    (($body = $this->isolateContext(
                        $generator ? array(null, "allowYield" => true) : null,
                        "parseFunctionBody"
                    )) || true) &&
                    $this->scanner->consume("}")
                ) {

                    if ($prop[0] instanceof Node\Identifier &&
                        $prop[0]->getName() === "constructor"
                    ) {
                        $kind = Node\MethodDefinition::KIND_CONSTRUCTOR;
                    }

                    $body->setStartPosition(
                        $tokenBodyStart->getLocation()->getStart()
                    );
                    $body->setEndPosition($this->scanner->getPosition());
                    
                    $nodeFn = $this->createNode("FunctionExpression", $tokenFn);
                    $nodeFn->setParams($params);
                    $nodeFn->setBody($body);
                    $nodeFn->setGenerator($generator);

                    $node = $this->createNode("MethodDefinition", $position);
                    $node->setKey($prop[0]);
                    $node->setValue($this->completeNode($nodeFn));
                    $node->setKind($kind);
                    $node->setComputed($prop[1]);
                    return $this->completeNode($node);
                }
            }
        }
        
        if ($error) {
            return $this->error();
        } else {
            $this->scanner->setState($state);
        }
        return null;
    }
    
    /**
     * Parses parameters in an arrow function. If the parameters are wrapped in
     * round brackets, the returned value is an array where the first element
     * is the parameters list and the second element is the open round brackets,
     * this is needed to know the start position
     * 
     * @return Node\Identifier|array|null
     */
    protected function parseArrowParameters()
    {
        if ($param = $this->parseIdentifier(self::ID_MIXED, "=>")) {
            return $param;
        } elseif ($token = $this->scanner->consume("(")) {
            
            $params = $this->parseFormalParameterList();
            
            if ($params !== null && $this->scanner->consume(")")) {
                return array($params, $token);
            }
        }
        return null;
    }
    
    /**
     * Parses the body of an arrow function. The returned value is an array
     * where the first element is the function body and the second element is
     * a boolean indicating if the body is wrapped in curly braces
     * 
     * @return array|null
     */
    protected function parseConciseBody()
    {
        if ($token = $this->scanner->consume("{")) {
            
            if (($body = $this->isolateContext(
                    null,
                    "parseFunctionBody"
                )) &&
                $this->scanner->consume("}")
            ) {
                $body->setStartPosition($token->getLocation()->getStart());
                $body->setEndPosition($this->scanner->getPosition());
                return array($body, false);
            }
            
            return $this->error();
        } elseif (!$this->scanner->isBefore(array("{")) &&
            $body = $this->isolateContext(
                array("allowYield" => false),
                "parseAssignmentExpression"
            )
        ) {
            return array($body, true);
        }
        return null;
    }
    
    /**
     * Parses an arrow function
     * 
     * @return Node\ArrowFunctionExpression|null
     */
    protected function parseArrowFunction()
    {
        $state = $this->scanner->getState();
        if (($params = $this->parseArrowParameters()) !== null) {
            
            if ($this->scanner->noLineTerminators() &&
                $this->scanner->consume("=>")
            ) {
                
                if ($body = $this->parseConciseBody()) {
                    if (is_array($params)) {
                        $pos = $params[1];
                        $params = $params[0];
                    } else {
                        $pos = $params;
                        $params = array($params);
                    }
                    $node = $this->createNode("ArrowFunctionExpression", $pos);
                    $node->setParams($params);
                    $node->setBody($body[0]);
                    $node->setExpression($body[1]);
                    return $this->completeNode($node);
                }
            
                return $this->error();
            }
        }
        $this->scanner->setState($state);
        return null;
    }
    
    /**
     * Parses an object literal
     * 
     * @return Node\ObjectExpression|null
     */
    protected function parseObjectLiteral()
    {
        if ($token = $this->scanner->consume("{")) {
            
            $properties = array();
            while ($prop = $this->parsePropertyDefinition()) {
                $properties[] = $prop;
                if (!$this->scanner->consume(",")) {
                    break;
                }
            }
            
            if ($this->scanner->consume("}")) {
                
                $node = $this->createNode("ObjectExpression", $token);
                if ($properties) {
                    $node->setProperties($properties);
                }
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a property in an object literal
     * 
     * @return Node\Property|null
     */
    protected function parsePropertyDefinition()
    {
        $state = $this->scanner->getState();
        if (($property = $this->parsePropertyName()) &&
            $this->scanner->consume(":")
        ) {
            $value = $this->isolateContext(
                array("allowIn" => true), "parseAssignmentExpression"
            );
            if ($value) {
                $startPos = isset($property[2]) ? $property[2] : $property[0];
                $node = $this->createNode("Property", $startPos);
                $node->setKey($property[0]);
                $node->setValue($value);
                $node->setComputed($property[1]);
                return $this->completeNode($node);
            }

            return $this->error();
            
        }
        
        $this->scanner->setState($state);
        if ($property = $this->parseMethodDefinition()) {

            $node = $this->createNode("Property", $property);
            $node->setKey($property->getKey());
            $node->setValue($property->getValue());
            $node->setComputed($property->getComputed());
            $kind = $property->getKind();
            if ($kind !== Node\MethodDefinition::KIND_GET &&
                $kind !== Node\MethodDefinition::KIND_SET
            ) {
                $node->setMethod(true);
                $node->setKind(Node\Property::KIND_INIT);
            } else {
                $node->setKind($kind);
            }
            return $this->completeNode($node);
            
        } elseif ($key = $this->parseIdentifier(self::ID_MIXED)) {
            
            $node = $this->createNode("Property", $key);
            $node->setShorthand(true);
            $node->setKey($key);
            $value = $this->isolateContext(
                array("allowIn" => true), "parseInitializer"
            );
            $node->setValue($value ? $value : $key);
            return $this->completeNode($node);
            
        }
        return null;
    }
    
    /**
     * Parses an itlializer
     * 
     * @return Node\Node|null
     */
    protected function parseInitializer()
    {
        if ($this->scanner->consume("=")) {
            
            if ($value = $this->parseAssignmentExpression()) {
                return $value;
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an object binding pattern
     * 
     * @return Node\ObjectPattern|null
     */
    protected function parseObjectBindingPattern()
    {
        if ($token = $this->scanner->consume("{")) {
            
            $properties = array();
            while ($prop = $this->parseBindingProperty()) {
                $properties[] = $prop;
                if (!$this->scanner->consume(",")) {
                    break;
                }
            }
            
            if ($this->scanner->consume("}")) {
                $node = $this->createNode("ObjectPattern", $token);
                if ($properties) {
                    $node->setProperties($properties);
                }
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a property in an object binding pattern
     * 
     * @return Node\AssignmentProperty|null
     */
    protected function parseBindingProperty()
    {
        $state = $this->scanner->getState();
        if (($key = $this->parsePropertyName()) &&
            $this->scanner->consume(":")
        ) {
            
            if ($value = $this->parseBindingElement()) {
                $startPos = isset($key[2]) ? $key[2] : $key[0];
                $node = $this->createNode("AssignmentProperty", $startPos);
                $node->setKey($key[0]);
                $node->setComputed($key[1]);
                $node->setValue($value);
                return $this->completeNode($node);
            }
            
            return $this->error();
            
        }
            
        $this->scanner->setState($state);
        if ($property = $this->parseSingleNameBinding()) {
            
            $node = $this->createNode("AssignmentProperty", $property);
            $node->setShorthand(true);
            if ($property instanceof Node\AssignmentPattern) {
                $node->setKey($property->getLeft());
            } else {
                $node->setKey($property);
            }
            $node->setValue($property);
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses an expression
     * 
     * @return Node\Node|null
     */
    protected function parseExpression()
    {
        $list = $this->charSeparatedListOf("parseAssignmentExpression");
        
        if (!$list) {
            return null;
        } elseif (count($list) === 1) {
            return $list[0];
        } else {
            $node = $this->createNode("SequenceExpression", $list);
            $node->setExpressions($list);
            return $this->completeNode($node);
        }
    }
    
    /**
     * Parses an assignment expression
     * 
     * @return Node\Node|null
     */
    protected function parseAssignmentExpression()
    {
        if ($expr = $this->parseArrowFunction()) {
            return $expr;
        } elseif ($this->context->allowYield && $expr = $this->parseYieldExpression()) {
            return $expr;
        } elseif ($expr = $this->parseConditionalExpression()) {
            
            $exprTypes = array(
                "ConditionalExpression", "LogicalExpression",
                "BinaryExpression", "UpdateExpression", "UnaryExpression"
            );
            
            if (!in_array($expr->getType(), $exprTypes)) {
                
                $operators = $this->assignmentOperators;
                if ($operator = $this->scanner->consumeOneOf($operators)) {
                    
                    $right = $this->parseAssignmentExpression();
                    if ($right) {
                        $node = $this->createNode(
                            "AssignmentExpression", $expr
                        );
                        $node->setLeft($this->expressionToPattern($expr));
                        $node->setOperator($operator->getValue());
                        $node->setRight($right);
                        return $this->completeNode($node);
                    }
                    return $this->error();
                }
            }
            return $expr;
        }
        return null;
    }
    
    /**
     * Parses a conditional expression
     * 
     * @return Node\Node|null
     */
    protected function parseConditionalExpression()
    {
        if ($test = $this->parseLogicalBinaryExpression()) {
            
            if ($this->scanner->consume("?")) {
                
                $consequent = $this->parseAssignmentExpression();
                if ($consequent && $this->scanner->consume(":") &&
                    $alternate = $this->parseAssignmentExpression()
                ) {
                
                    $node = $this->createNode("ConditionalExpression", $test);
                    $node->setTest($test);
                    $node->setConsequent($consequent);
                    $node->setAlternate($alternate);
                    return $this->completeNode($node);
                }
                
                return $this->error();
            } else {
                return $test;
            }
        }
        return null;
    }
    
    /**
     * Parses a logical or a binary expression
     * 
     * @return Node\Node|null
     */
    protected function parseLogicalBinaryExpression()
    {
        $operators = $this->logicalBinaryOperators;
        if (!$this->context->allowIn) {
            unset($operators["in"]);
        }
        
        if (!($exp = $this->parseUnaryExpression())) {
            return null;
        }
        
        $list = array($exp);
        while ($token = $this->scanner->consumeOneOf(array_keys($operators))) {
            if (!($exp = $this->parseUnaryExpression())) {
                return $this->error();
            }
            $list[] = $token->getValue();
            $list[] = $exp;
        }
        
        $len = count($list);
        if ($len > 1) {
            $maxGrade = max($operators);
            for ($grade = $maxGrade; $grade >= 0; $grade--) {
                $class = $grade < 2 ? "LogicalExpression" : "BinaryExpression";
                for ($i = 1; $i < $len; $i += 2) {
                    if ($operators[$list[$i]] === $grade) {
                        $node = $this->createNode($class, $list[$i - 1]);
                        $node->setLeft($list[$i - 1]);
                        $node->setOperator($list[$i]);
                        $node->setRight($list[$i + 1]);
                        $node = $this->completeNode(
                            $node, $list[$i + 1]->getLocation()->getEnd()
                        );
                        array_splice($list, $i - 1, 3, array($node));
                        $i -= 2;
                        $len = count($list);
                    }
                }
            }
        }
        return $list[0];
    }
    
    /**
     * Parses a unary expression
     * 
     * @return Node\Node|null
     */
    protected function parseUnaryExpression()
    {
        if ($expr = $this->parsePostfixExpression()) {
            return $expr;
        } elseif ($token = $this->scanner->consumeOneOf($this->unaryOperators)) {
            if ($argument = $this->parseUnaryExpression()) {
                
                $op = $token->getValue();
                
                //Deleting a variable without accessing its properties is a
                //syntax error in strict mode
                if ($op === "delete" &&
                    $this->scanner->getStrictMode() &&
                    $argument instanceof Node\Identifier) {
                    return $this->error(
                        "Deleting an unqualified identifier is not allowed in strict mode"
                    );
                }
                
                if ($op === "++" || $op === "--") {
                    $node = $this->createNode("UpdateExpression", $token);
                    $node->setPrefix(true);
                } else {
                    $node = $this->createNode("UnaryExpression", $token);
                }
                $node->setOperator($op);
                $node->setArgument($argument);
                return $this->completeNode($node);
            }

            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a postfix expression
     * 
     * @return Node\Node|null
     */
    protected function parsePostfixExpression()
    {
        if ($argument = $this->parseLeftHandSideExpression()) {
            
            if ($this->scanner->noLineTerminators() &&
                $token = $this->scanner->consumeOneOf($this->postfixOperators)
            ) {
                
                $node = $this->createNode("UpdateExpression", $argument);
                $node->setOperator($token->getValue());
                $node->setArgument($argument);
                return $this->completeNode($node);
            }
            
            return $argument;
        }
        return null;
    }
    
    /**
     * Parses a left hand side expression
     * 
     * @return Node\Node|null
     */
    protected function parseLeftHandSideExpression()
    {
        $object = null;
        $newTokens = array();
        
        //Parse all occurences of "new"
        if ($newToken = $this->scanner->isBefore(array("new"))) {
            while ($newToken = $this->scanner->consume("new")) {
                if ($this->scanner->consume(".")) {
                    //new.target
                    if ($this->scanner->consume("target")) {
                    
                        $node = $this->createNode("MetaProperty", $newToken);
                        $node->setMeta("new");
                        $node->setProperty("target");
                        $object = $this->completeNode($node);
                        break;
                    
                    } else {
                        return $this->error();
                    }
                }
                $newTokens[] = $newToken;
            }
        }
        
        $newTokensCount = count($newTokens);
        
        if (!$object &&
            !($object = $this->parseSuperPropertyOrCall()) &&
            !($object = $this->parsePrimaryExpression())
        ) {
            
            if ($newTokensCount) {
                return $this->error();
            }
            return null;
        }
        
        $valid = true;
        $properties = array();
        while (true) {
            if ($this->scanner->consume(".")) {
                if ($property = $this->parseIdentifier(self::ID_ALLOW_ALL)) {
                    $properties[] = array(
                        "type"=> "id",
                        "info" => $property
                    );
                } else {
                    $valid = false;
                    break;
                }
            } elseif ($this->scanner->consume("[")) {
                if (($property = $this->isolateContext(
                        array("allowIn" => true), "parseExpression"
                    )) &&
                    $this->scanner->consume("]")
                ) {
                    $properties[] = array(
                        "type" => "computed",
                        "info" => array(
                            $property, $this->scanner->getPosition()
                        )
                    );
                } else {
                    $valid = false;
                    break;
                }
            } elseif ($property = $this->parseTemplateLiteral()) {
                $properties[] = array(
                    "type"=> "template",
                    "info" => $property
                );
            } elseif (($args = $this->parseArguments()) !== null) {
                $properties[] = array(
                    "type"=> "args",
                    "info" => array($args, $this->scanner->getPosition())
                );
            } else {
                break;
            }
        }
        
        $propCount = count($properties);
        
        if (!$valid) {
            return $this->error();
        } elseif (!$propCount && !$newTokensCount) {
            return $object;
        }
        
        $node = null;
        $endPos = $object->getLocation()->getEnd();
        foreach ($properties as $i => $property) {
            $lastNode = $node ? $node : $object;
            if ($property["type"] === "args") {
                if ($newTokensCount) {
                    $node = $this->createNode(
                        "NewExpression", array_pop($newTokens)
                    );
                    $newTokensCount--;
                } else {
                    $node = $this->createNode("CallExpression", $lastNode);
                }
                $node->setCallee($lastNode);
                $node->setArguments($property["info"][0]);
                $endPos = $property["info"][1];
            } elseif ($property["type"] === "id") {
                $node = $this->createNode("MemberExpression", $lastNode);
                $node->setObject($lastNode);
                $node->setProperty($property["info"]);
                $endPos = $property["info"]->getLocation()->getEnd();
            } elseif ($property["type"] === "computed") {
                $node = $this->createNode("MemberExpression", $lastNode);
                $node->setObject($lastNode);
                $node->setProperty($property["info"][0]);
                $node->setComputed(true);
                $endPos = $property["info"][1];
            } elseif ($property["type"] === "template") {
                $node = $this->createNode("TaggedTemplateExpression", $object);
                $node->setTag($lastNode);
                $node->setQuasi($property["info"]);
                $endPos = $property["info"]->getLocation()->getEnd();
            }
            $node = $this->completeNode($node, $endPos);
        }
        
        //Wrap the result in multiple NewExpression if there are "new" tokens
        if ($newTokensCount) {
            for ($i = $newTokensCount - 1; $i >= 0; $i--) {
                $lastNode = $node ? $node : $object;
                $node = $this->createNode("NewExpression", $newTokens[$i]);
                $node->setCallee($lastNode);
                $node = $this->completeNode($node);
            }
        }
        
        return $node;
    }
    
    /**
     * Parses a spread element
     * 
     * @return Node\SpreadElement|null
     */
    protected function parseSpreadElement()
    {
        if ($token = $this->scanner->consume("...")) {
            
            $argument = $this->isolateContext(
                array("allowIn" => true), "parseAssignmentExpression"
            );
            if ($argument) {
                $node = $this->createNode("SpreadElement", $token);
                $node->setArgument($argument);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an array literal
     * 
     * @return Node\ArrayExpression|null
     */
    protected function parseArrayLiteral()
    {
        if ($token = $this->scanner->consume("[")) {
            
            $elements = array();
            while (true) {
                if ($elision = $this->parseElision()) {
                    $elements = array_merge(
                        $elements, array_fill(0, $elision, null)
                    );
                }
                if (($element = $this->parseSpreadElement()) ||
                    ($element = $this->isolateContext(
                        array("allowIn" => true), "parseAssignmentExpression"
                    ))
                ) {
                    $elements[] = $element;
                    if (!$this->scanner->consume(",")) {
                        break;
                    }
                } else {
                    break;
                }
            }
            
            if ($this->scanner->consume("]")) {
                $node = $this->createNode("ArrayExpression", $token);
                $node->setElements($elements);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an arguments list wrapped in round brackets
     * 
     * @return array|null
     */
    protected function parseArguments()
    {
        if ($this->scanner->consume("(")) {
            
            if (($args = $this->parseArgumentList()) !== null &&
                $this->scanner->consume(")")
            ) {
                return $args;
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an arguments list
     * 
     * @return array|null
     */
    protected function parseArgumentList()
    {
        $list = array();
        $start = $valid = true;
        while (true) {
            $spread = $this->scanner->consume("...");
            $exp = $this->isolateContext(
                array("allowIn" => true), "parseAssignmentExpression"
            );
            if (!$exp) {
                $valid = $valid && $start && !$spread;
                break;
            }
            if ($spread) {
                $node = $this->createNode("SpreadElement", $spread);
                $node->setArgument($exp);
                $list[] = $this->completeNode($node);
            } else {
                $list[] = $exp;
            }
            $start = false;
            $valid = true;
            if (!$this->scanner->consume(",")) {
                break;
            } else {
                $valid = false;
            }
        }
        if (!$valid) {
            return $this->error();
        }
        return $list;
    }
    
    /**
     * Parses a super call or a super property
     * 
     * @return Node\Node|null
     */
    protected function parseSuperPropertyOrCall()
    {
        if ($token = $this->scanner->consume("super")) {
            
            $super = $this->completeNode($this->createNode("Super", $token));
            
            if (($args = $this->parseArguments()) !== null) {
                $node = $this->createNode("CallExpression", $token);
                $node->setArguments($args);
                $node->setCallee($super);
                return $this->completeNode($node);
            }
            
            $node = $this->createNode("MemberExpression", $token);
            $node->setObject($super);
            
            if ($this->scanner->consume(".")) {
                
                if ($property = $this->parseIdentifier(self::ID_ALLOW_ALL)) {
                    $node->setProperty($property);
                    return $this->completeNode($node);
                }
            } elseif ($this->scanner->consume("[") &&
                ($property = $this->isolateContext(
                    array("allowIn" => true), "parseExpression"
                )) &&
                $this->scanner->consume("]")
            ) {
                
                $node->setProperty($property);
                $node->setComputed(true);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a primary expression
     * 
     * @return Node\Node|null
     */
    protected function parsePrimaryExpression()
    {
        if ($token = $this->scanner->consume("this")) {
            $node = $this->createNode("ThisExpression", $token);
            return $this->completeNode($node);
        } elseif ($exp = $this->parseIdentifier(self::ID_MIXED)) {
            return $exp;
        } elseif ($exp = $this->parseLiteral()) {
            return $exp;
        } elseif ($exp = $this->parseArrayLiteral()) {
            return $exp;
        } elseif ($exp = $this->parseObjectLiteral()) {
            return $exp;
        } elseif ($exp = $this->parseFunctionOrGeneratorExpression()) {
            return $exp;
        } elseif ($exp = $this->parseClassExpression()) {
            return $exp;
        } elseif ($exp = $this->parseRegularExpressionLiteral()) {
            return $exp;
        } elseif ($exp = $this->parseTemplateLiteral()) {
            return $exp;
        } elseif ($token = $this->scanner->consume("(")) {
            
            if (($exp = $this->isolateContext(
                    array("allowIn" => true), "parseExpression"
                )) &&
                $this->scanner->consume(")")
            ) {
                
                $node = $this->createNode("ParenthesizedExpression", $token);
                $node->setExpression($exp);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an identifier
     * 
     * @param int   $mode       Parsing mode, one of the id parsing mode
     *                          constants
     * @param string $after     If a string is passed in this parameter, the
     *                          identifier is parsed only if preceeds this string
     * 
     * @return Node\Identifier|null
     */
    protected function parseIdentifier($mode, $after = null)
    {
        $token = $this->scanner->getToken();
        if (!$token) {
            return null;
        }
        if ($after !== null) {
            $next = $this->scanner->getNextToken();
            if (!$next || $next->getValue() !== $after) {
                return null;
            }
        }
        $type = $token->getType();
        switch ($type) {
            case Token::TYPE_BOOLEAN_LITERAL:
            case Token::TYPE_NULL_LITERAL:
                if ($mode !== self::ID_ALLOW_ALL) {
                    return null;
                }
            break;
            case Token::TYPE_KEYWORD:
                if ($mode === self::ID_ALLOW_NOTHING) {
                    return null;
                } elseif ($mode === self::ID_MIXED &&
                    $this->scanner->isStrictModeKeyword($token)
                ) {
                    return null;
                }
            break;
            default:
                if ($type !== Token::TYPE_IDENTIFIER) {
                    return null;
                }
            break;
        }
        $this->scanner->consumeToken();
        $node = $this->createNode("Identifier", $token);
        $node->setName($token->getValue());
        return $this->completeNode($node);
    }
    
    /**
     * Parses a literal
     * 
     * @return Node\Literal|null
     */
    protected function parseLiteral()
    {
        if ($token = $this->scanner->getToken()) {
            if ($token->getType() === Token::TYPE_NULL_LITERAL) {
                $this->scanner->consumeToken();
                $node = $this->createNode("NullLiteral", $token);
                return $this->completeNode($node);
            } elseif ($token->getType() === Token::TYPE_BOOLEAN_LITERAL) {
                $this->scanner->consumeToken();
                $node = $this->createNode("BooleanLiteral", $token);
                $node->setRaw($token->getValue());
                return $this->completeNode($node);
            } elseif ($literal = $this->parseStringLiteral()) {
                return $literal;
            } elseif ($literal = $this->parseNumericLiteral()) {
                return $literal;
            }
        }
        return null;
    }
    
    /**
     * Parses a string literal
     * 
     * @return Node\StringLiteral|null
     */
    protected function parseStringLiteral()
    {
        $token = $this->scanner->getToken();
        if ($token && $token->getType() === Token::TYPE_STRING_LITERAL) {
            $val = $token->getValue();
            if ($this->scanner->getStrictMode()) {
                $this->preventLegacyOctalSyntax($val);
            }
            $this->scanner->consumeToken();
            $node = $this->createNode("StringLiteral", $token);
            $node->setRaw($val);
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses a numeric literal
     * 
     * @return Node\NumericLiteral|null
     */
    protected function parseNumericLiteral()
    {
        $token = $this->scanner->getToken();
        if ($token && $token->getType() === Token::TYPE_NUMERIC_LITERAL) {
            $val = $token->getValue();
            if ($this->scanner->getStrictMode()) {
                $this->preventLegacyOctalSyntax($val, true);
            }
            $this->scanner->consumeToken();
            $node = $this->createNode("NumericLiteral", $token);
            $node->setRaw($val);
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses a template literal
     * 
     * @return Node\Literal|null
     */
    protected function parseTemplateLiteral()
    {
        $token = $this->scanner->getToken();
        
        if (!$token || $token->getType() !== Token::TYPE_TEMPLATE) {
            return null;
        }
        
        //Do not parse templates parts
        $val = $token->getValue();
        if ($val[0] !== "`") {
            return null;
        }
        
        $quasis = $expressions = array();
        $valid = false;
        do {
            $this->scanner->consumeToken();
            $val = $token->getValue();
            $this->preventLegacyOctalSyntax($val);
            $lastChar = substr($val, -1);
            
            $quasi = $this->createNode("TemplateElement", $token);
            $quasi->setRawValue($val);
            if ($lastChar === "`") {
                $quasi->setTail(true);
                $quasis[] = $this->completeNode($quasi);
                $valid = true;
                break;
            } else {
                $quasis[] = $this->completeNode($quasi);
                $exp = $this->isolateContext(
                    array("allowIn" => true), "parseExpression"
                );
                if ($exp) {
                    $expressions[] = $exp;
                } else {
                    $valid = false;
                    break;
                }
            }
            
            $token = $this->scanner->getToken();
        } while ($token && $token->getType() === Token::TYPE_TEMPLATE);
        
        if ($valid) {
            $node = $this->createNode("TemplateLiteral", $quasis[0]);
            $node->setQuasis($quasis);
            $node->setExpressions($expressions);
            return $this->completeNode($node);
        }
        
        return $this->error();
    }
    
    /**
     * Parses a regular expression literal
     * 
     * @return Node\Literal|null
     */
    protected function parseRegularExpressionLiteral()
    {
        if ($token = $this->scanner->reconsumeCurrentTokenAsRegexp()) {
            $this->scanner->consumeToken();
            $node = $this->createNode("RegExpLiteral", $token);
            $node->setRaw($token->getValue());
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parse directive prologues. The result is an array where the first element
     * is the array of parsed nodes and the second element is the array of
     * directive prologues values
     * 
     * @return array|null
     */
    protected function parseDirectivePrologues()
    {
        $directives = $nodes = array();
        while (($token = $this->scanner->getToken()) &&
            $token->getType() === Token::TYPE_STRING_LITERAL
        ) {
            $directive = substr($token->getValue(), 1, -1);
            if ($directive === "use strict") {
                $directives[] = $directive;
                $directiveNode = $this->parseStringLiteral();
                $this->assertEndOfStatement();
                $node = $this->createNode("ExpressionStatement", $directiveNode);
                $node->setExpression($directiveNode);
                $nodes[] = $this->completeNode($node);
            } else {
                break;
            }
        }
        return count($nodes) ? array($nodes, $directives) : null;
    }
    
    /**
     * If a number is in the legacy octal form or if a string contains a legacy
     * octal escape, it throws a syntax error
     * 
     * @param string  $val      Value to check
     * @param bool    $number   True if the value is a number
     * 
     * @return void
     */
    protected function preventLegacyOctalSyntax($val, $number = false)
    {
        $error = false;
        if ($number) {
            $error = preg_match("#^0[0-7]+$#", $val);
        } elseif (strpos($val, "\\") !== false &&
            preg_match_all("#(\\\\+)([0-7]{1,2})#", $val, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (strlen($match[1]) % 2 && $match[2] !== "0") {
                    $error = true;
                    break;
                }
            }
        }
        if ($error) {
            return $this->error("Octal literals are not allowed in strict mode");
        }
    }
}