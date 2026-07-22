import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { LoginForm } from "./login-form";

vi.mock("./actions", () => ({
  login: vi.fn(),
}));

describe("LoginForm", () => {
  it("renders labeled, accessible fields and a submit button", () => {
    render(<LoginForm />);

    expect(screen.getByLabelText("organization")).toBeInTheDocument();
    expect(screen.getByLabelText("email")).toHaveAttribute("type", "email");
    expect(screen.getByLabelText("password")).toHaveAttribute("type", "password");
    expect(screen.getByRole("button", { name: "submit" })).toBeEnabled();
  });

  it("marks every field required (progressive enhancement)", () => {
    render(<LoginForm />);

    for (const field of ["organization", "email", "password"]) {
      expect(screen.getByLabelText(field)).toBeRequired();
    }
  });

  it("shows no alert before submission", () => {
    render(<LoginForm />);

    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });

  it("uses autocomplete hints for password managers", () => {
    render(<LoginForm />);

    expect(screen.getByLabelText("password")).toHaveAttribute(
      "autocomplete",
      "current-password",
    );
    expect(screen.getByLabelText("email")).toHaveAttribute("autocomplete", "email");
  });
});
