<?php declare(strict_types=1);
/**
 * Implemented by HammerCode OÜ team https://www.hammercode.eu/
 *
 * @copyright HammerCode OÜ https://www.hammercode.eu/
 * @license proprietär
 * @link https://www.hammercode.eu/
 */

namespace WizmoGmbh\IvyPayment\IvyApi;

class prefill
{
    /** @var string|null */
    private string $email;
    /** @var string|null */
    private string $phone;

    /**
     * @param string|null $email
     * @param string|null $phone
     */
    public function __construct(?string $email = null,?string $phone = null)
    {
        $this->email = $email;
        $this->phone = $phone;
    }


    /**
     * @return string
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * @param string|null $email
     * @return $this
     */
    public function setEmail(?string $email): prefill
    {
        $this->email = $email;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getPhone(): ?string
    {
        return $this->phone;
    }

    /**
     * @param string|null $phone
     * @return $this
     */
    public function setPhone(?string $phone): prefill
    {
        $this->phone = $phone;
        return $this;
    }

}
