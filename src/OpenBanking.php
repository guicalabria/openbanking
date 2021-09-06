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
	private $clientscret;
   private $sandbox = false;

	// Armazena a última token processada pelo método obterToken()
	private $tokenEmCache;

	// Tempo em segundos válido da token gerada pelo BB
	private $ttl_token = 600;
	// Porcentagem tolerável antes de tentar renovar a token (0 a 100). Se ultrapassar, tente renová-la automaticamente. // 0 (zero) -> sempre renova
	// 100 -> tenta usá-la até o final do tempo
	private $porcentagemtoleravel_ttl_token = 80;
	// Tempo limite para obter resposta de 20 segundos
	private $timeout = 20;

   private $tokenDir='';

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
      if ( isset($params['clientid']) ) $this->client_id = $params['clientid'];
      if ( isset($params['clientscret']) ) $this->client_scret = $params['clientscret'];
      if ( isset($params['sandbox']) && \is_bool($params['sandbox']) ) $this->sandbox = $params['sandbox'];
      if ( isset($params['appdevkey']) ) $this->appdevkey = $params['appdevkey'];
      if ( isset($params['tokendir']) ){
         if ( !\is_dir($params['tokendir']) ){
            throw new \Exception('Diretório ('.$params['tokendir'].') para token inválido');
         }
         $this->tokendir = $params['tokendir'];
      }
	}

	/**
	 * Inicia as configurações do Curl útil para
	 * realizar as requisições de token e registro de boleto
	 * @returns resource Curl pré-configurado
	 */
	private function prepararCurl($autorizacao=false)
	{
		$curl = curl_init();
		curl_setopt_array($curl, array(
			//CURLOPT_BINARYTRANSFER => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_POST => true,
			CURLOPT_TIMEOUT => $this->_timeout,
			CURLOPT_MAXREDIRS => 3
		));

      if ( $autorizacao===false ){

      }
      else {
         curl_setopt_array($curl, array(
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER => array(
               'Authorization: Basic ' . base64_encode($this->clientid . ':' . $this->clientscret),
               'Cache-Control: no-cache'
               )
            ));
      }
      return $curl;
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
         case '001':if ($autorizacao===false) return $this->sandbox ? 'https://oauth.sandbox.bb.com.br/oauth/token':'';
                  else return $this->sandbox ? 'https://api.sandbox.bb.com.br/cobrancas/v2':'https://api.bb.com.br/cobrancas/v2';
            break;
      }
      throw new \Exception('Dados inválidos para URL');
	}

	/**
  	 * Inicia as configurações do Curl útil para realizar as requisições
  	 * @param bool $naousarcache		Especifica se o programador aceita ou não obter uma token já salva em cache
  	 * @returns object|bool Objeto, caso o token foi recebido com êxito, ou false, caso contrário
  	 */
	public function obterToken($naousarcache = true)
	{
			if ($this->tokenEmCache && !$naousarcache) return $this->tokenEmCache;

         if ( $this->tokenDir!='' )
         {
            // Define o caminho para o arquivo de cache
            $caminhodoarquivodecache = $this->tokenDir.DIRECTORY_SEPARATOR .'token.txt';

            if (!$naousarcache)
            {
               // Se o arquivo existir, retorna o timestamp da última modificação. Se não, retorna "false"
               $timedamodificacao = @filemtime($caminhodoarquivodecache);

               // Testa se o arquivo existe e se o seu conteúdo (token) foi modificado dentro do tempo tolerável
               if ($timedamodificacao && $timedamodificacao + self::$ttl_token * self::$porcentagemtoleravel_ttl_token / 100 > time())
               {
                  // Tenta abrir o arquivo para leitura e escrita
                  $arquivo = @fopen($caminhodoarquivodecache, 'c+');

                  // Se conseguir-se abrir o arquivo...
                  if ($arquivo)
                  {
                     // trava-o para escrita enquanto os dados são lidos
                     flock($arquivo, LOCK_SH);

                     // Lê o conteúdo do arquivo
                     $dados = '';
                     do
                        $dados .= fread($arquivo, 1024);
                     while (!feof($arquivo));

                     fclose($arquivo);

                     // Retorna apenas a token salva no arquivo
                     return $this->tokenEmCache = $dados;
                  }
               }
            }
         }

         try{

            $curl = $this->prepararCurl();
            curl_setopt_array($curl, array(
               CURLOPT_URL => $this->getURL(true)
            ));
         }catch(Exception $e){
            throw new \Exception('Erro ao obter token:'.$e->getMessage());
         }

			$resposta = curl_exec($curl);
			curl_close($curl);

			// Recebe os dados do WebService no formato JSON.
			// Realiza o parse da resposta e retorna.
			// Caso seja um valor vazio ou fora do formato, retorna false.
			$resultado = json_decode($resposta);
\var_dump($resultado);
			// Se o valor salvo em "$resultado" for um objeto e se existir o atributo "access_token" nele...
			if ($resultado)
			{
				if (isset($resultado->access_token))
				{
               if ( $this->tokenDir='' )
               {
                  // Armazena token em cache apenas se a porcentagem tolerável sobre o tempo da token for superior a 0%
                  if (self::$porcentagemtoleravel_ttl_token > 0)
                  {
                     // Tenta abrir o arquivo para leitura e escrita
                     $arquivo = @fopen($caminhodoarquivodecache, 'c+');

                     // Se conseguir-se abrir o arquivo...
                     if ($arquivo) {
                        // trava-o para leitura e escrita
                        flock($arquivo, LOCK_EX);

                        // apaga todo o seu conteúdo
                        ftruncate($arquivo, 0);

                        // escreve a token no arquivo
                        fwrite($arquivo, $resultado->access_token);

                        fclose($arquivo);
                     }
                  }
               }

               $this->tokenEmCache = $resultado->access_token;
					return $this->tokenEmCache;
				}
			}

		return false;
	}

}
