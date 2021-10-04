<?php
namespace Plotag\Enter8;

use Exception;

/**
 *  Class responsible for communication with Branco do Brasil WebServices
 *
 * @category  library
 * @package   BoletoWebService
 * @license   https://opensource.org/licenses/MIT MIT
 * @author    Reginaldo Coimbra Vieira < recovieira@gmail.com >
 * @link      https://github.com/recovieira/bbboletowebservice.git for the canonical source repository
 */


class OpenBanking {

	private $banco;
	private $appdevkey;
	private $clientid;
	private $clientsecret;
   private $sandbox = false;

	// Armazena a última token processada pelo método obterToken()
	private $tokenEmCache;

	// Tempo em segundos válido da token gerada pelo BB
	private static $ttl_token = 600;
	// Porcentagem tolerável antes de tentar renovar a token (0 a 100). Se ultrapassar, tente renová-la automaticamente. // 0 (zero) -> sempre renova
	// 100 -> tenta usá-la até o final do tempo
	private static $porcentagemtoleravel_ttl_token = 80;
	// Tempo limite para obter resposta de 20 segundos
	private $timeout = 20;

	private $tiporequisicao = '';

	/**
  	 * Construtor do Consumidor de WebService do BB
  	 *
  	 * @param array $params Parâmetros iniciais para construção do objeto
  	 * @throws Exception Quando o banco não é suportado
  	 */
	public function __construct(array $params)
	{
      if ( isset($params['banco']) ) {
         if ( $params['banco']!='001' ){
            throw new \Exception('Banco ('.$params['banco'].') não possui suporte para OpenBanking');
         }

         $this->banco = $params['banco'];
      }
      if ( isset($params['clientid']) ) $this->clientid = trim($params['clientid']);
      if ( isset($params['clientsecret']) ) $this->clientsecret = trim($params['clientsecret']);
      if ( isset($params['sandbox']) && \is_bool($params['sandbox']) ) $this->sandbox = $params['sandbox'];
      if ( isset($params['appdevkey']) ) $this->appdevkey = $params['appdevkey'];
      if ( isset($params['tiporequisicao']) ) $this->tiporequisicao = $params['tiporequisicao'];
	}

	/**
	 * Inicia as configurações do Curl útil para
	 * realizar as requisições de token e registro de boleto
    * @param bollean $autorizacao Se for para preparar o CURL para solicitar token
	 * @returns resource Curl pré-configurado
    * @throws Exception Quando houver
	 */
	private function prepararCurl($autorizacao=false)
	{
      $escopo = '';
      /*Se for solicitar token exibir escopo */
      if ( $autorizacao!==false && $this->tiporequisicao=='' ){
         throw new \Exception('Escopo não configurado para preparar CURL ');
      }
      elseif ( $autorizacao!==false ){
         switch($this->tiporequisicao){
            case 'cobrancas.requisicao':$escopo = 'cobrancas.boletos-requisicao';
               break;
            case 'cobrancas.info':
            default:$escopo = 'cobrancas.boletos-info';
         }
      }

      try{
         $curl = curl_init();
         curl_setopt_array($curl, array(
            //CURLOPT_BINARYTRANSFER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_MAXREDIRS => 3
         ));

         if ( $autorizacao===false ){
            curl_setopt_array($curl, array(
               CURLOPT_HTTPHEADER =>  array(
                  //'Authorization: Bearer '.$this->obterToken(false),
                  'Authorization: Bearer '.$this->obterToken(true),
                  //'X-Application-Key: '.$this->appdevkey,
                  'Content-Type: application/json'
               )
            ));
         }
         else {
            curl_setopt_array($curl, array(
               CURLOPT_URL => $this->getURL(true),
               CURLOPT_POSTFIELDS => 'grant_type=client_credentials'.($escopo!=''?'&scope='.$escopo:''),
               CURLOPT_HTTPHEADER => array(
                  'Authorization: Basic ' . base64_encode($this->clientid . ':' . $this->clientsecret),
                  'Cache-Control: no-cache'
               )
            ));
         }
         return $curl;
      }catch(Exception $e){
         throw new \Exception('Erro ao iniciar CURL:'.$e->getMessage());
      }
	}

	/**
	 * Retorna a determinada URL
	 * @returns boll Curl pré-configurado
    * @throws Exception Quando dados inválidos poara URL
	 */
	private function getURL($autorizacao=false)
	{
      switch($this->banco=='001')
      {
         case '001':if ($autorizacao===true) return $this->sandbox ? 'https://oauth.sandbox.bb.com.br/oauth/token':'';
                  else return $this->sandbox ? 'https://api.hm.bb.com.br/cobrancas/v2':'https://api.bb.com.br/cobrancas/v2';
            break;
      }
      throw new \Exception('Dados inválidos para URL');
	}

	/**
  	 * Inicia as configurações do Curl útil para realizar as requisições
  	 * @param bool $naousarcache		Especifica se o programador aceita ou não obter uma token já salva em cache
  	 * @returns object|bool Objeto, caso o token foi recebido com êxito, ou false, caso contrário
    * @throws Exception Quando houver
  	 */
	public function obterToken($naousarcache = true)
	{
      try{
			if ($this->tokenEmCache && !$naousarcache) return $this->tokenEmCache;

         $curl = $this->prepararCurl(true);

         $resposta = curl_exec($curl);

         curl_close($curl);

			// Recebe os dados do WebService no formato JSON.
			// Realiza o parse da resposta e retorna.
			// Caso seja um valor vazio ou fora do formato, retorna false.
			$resultado = json_decode($resposta);

			// Se o valor salvo em "$resultado" for um objeto e se existir o atributo "access_token" nele...
			if ($resultado)
			{
				if (isset($resultado->access_token))
				{
               $this->tokenEmCache = $resultado->access_token;
					return $this->tokenEmCache;
				}
			}
         return false;

      }catch(Exception $e){
         throw new \Exception('Erro ao obter token:'.$e->getMessage());
      }
	}

	/**
  	 * Regitra o determinado boleto no banco
  	 * @param array $dados  Dados do para o boleto
  	 * @returns array|bool Objeto, caso o registro foi recebido com êxito retorna os dados, ou false, caso contrário
    * @throws Exception Quando houver
  	 */
   public function registrarBoleto(array $dados)
   {
      try{
            $campoPossivel = array('numeroConvenio','numeroCarteira','numeroVariacaoCarteira','codigoModalidade','dataEmissao','dataVencimento','valorOriginal','valorAbatimento','quantidadeDiasProtesto','quantidadeDiasNegativacao','orgaoNegativador',
               'indicadorAceiteTituloVencido','numeroDiasLimiteRecebimento','codigoAceite','codigoTipoTitulo','descricaoTipoTitulo','indicadorPermissaoRecebimentoParcial','numeroTituloBeneficiario','campoUtilizacaoBeneficiario','numeroTituloCliente',
               'mensagemBloquetoOcorrencia','desconto'=>array('tipo','dataExpiracao','porcentagem','valor'),'segundoDesconto'=>array('dataExpiracao','porcentagem','valor'),'terceiroDesconto'=>array('dataExpiracao','porcentagem','valor'),
               'jurosMora'=>array('tipo','porcentagem','valor'),'multa'=>array('tipo','data','porcentagem','valor'),'pagador'=>array('tipoInscricao','numeroInscricao','nome','endereco','cep','cidade','bairro','uf','telefone'),
               'beneficiarioFinal'=>array('tipoInscricao','numeroInscricao','nome'),'indicadorPix');

         $dadosBoleto = array();
         foreach($campoPossivel as $key=>$nome)
         {
            if ( is_array($nome) )
            {
               if ( !is_array($dados[$key]) ) continue;
               $dadosBoleto[$key] = array();

               foreach($nome as $nomeinterno)
               {
                  if ( isset($dados[$key][$nomeinterno]) ) $dadosBoleto[$key][$nomeinterno] = $dados[$key][$nomeinterno];
               }
            }
            elseif ( isset($dados[$nome]) ) $dadosBoleto[$nome] = $dados[$nome];
         }
         $curl = $this->prepararCurl();

         $encodedData = json_encode($dadosBoleto);
         //echo 'A'.$encodedData,"\n";
         $url = $this->getURL();
         $url = $this->getURL().'/boletos';
         $url = $this->getURL().'/?gw-dev-app-key='.$this->appdevkey;
         $url = $this->getURL().'/boletos?gw-dev-app-key='.$this->appdevkey.'&indicadorSituacao=A&agenciaBeneficiario=452&contaBeneficiario=123873&codigoEstadoTituloCobranca&dataInicioVencimento=14.09.2021&dataFimVencimento=14.09.2021';
         $url = $this->getURL().'/boletos/00031285570000003051/?gw-dev-app-key='.$this->appdevkey.'';
         $url = $this->getURL().'/boletos?gw-dev-app-key='.$this->appdevkey;
         //echo 'URL',"\n",$teste,"\n";
         curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            //CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $encodedData
         ));

			$resposta = curl_exec($curl);
			curl_close($curl);

         if ( $resposta )
         {
            // Recebe os dados do WebService no formato JSON.
            // Realiza o parse da resposta e retorna.
            // Caso seja um valor vazio ou fora do formato, retorna false.
            $resultado = json_decode($resposta,true);
            if ( isset($resultado['erros']) ){
               throw new \Exception(var_export($resultado['erros'],true));
            }
            elseif ( isset($resultado['error']) ){
               throw new \Exception($resultado['message']);
            }
            return $resultado;
         }
         else {
            throw new \Exception('Erro ao registrar boleto não foi possível conectar ao banco');
         }

      }catch(Exception $e){
         throw new \Exception('Erro ao registrar boleto:'.$e->getMessage());
      }
   }
	/**
  	 * Busca os dados do determinado boleto
  	 * @param array $busca  Dados para busca
  	 * @returns array|bool Objeto dados do(s) boleto(s)
    * @throws Exception Quando houver
  	 */
   public function getBoleto(array $dados)
   {
      try{

         $curl = $this->prepararCurl();

         $url = $this->getURL().'/boletos';
         $parametros = '?gw-dev-app-key='.$this->appdevkey;
         if ( isset($dados['id']) ) $url .= '/'.$dados['id'];
         if ( isset($dados['numeroConvenio']) ) $parametros .= '&numeroConvenio='.$dados['numeroConvenio'];

         $url .= $parametros;

         curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'GET',
         ));

			$resposta = curl_exec($curl);
			curl_close($curl);

         if ( $resposta )
         {
            // Recebe os dados do WebService no formato JSON.
            // Realiza o parse da resposta e retorna.
            // Caso seja um valor vazio ou fora do formato, retorna false.
            $resultado = json_decode($resposta,true);
            if ( isset($resultado['erros']) ){
               throw new \Exception(var_export($resultado['erros'],true));
            }
            elseif ( isset($resultado['error']) ){
               throw new \Exception($resultado['message']);
            }
            return $resultado;
         }
         else {
            throw new \Exception('Erro ao consultar boleto');
         }

      }catch(Exception $e){
         \print_r($e->getMessage());
         throw new \Exception('Erro ao consultar boleto:'.$e->getMessage());
      }
   }
}
